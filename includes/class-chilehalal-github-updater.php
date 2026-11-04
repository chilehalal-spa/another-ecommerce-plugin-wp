<?php
/**
 * Actualizador desde GitHub Releases.
 *
 * Usa el soporte nativo de WordPress (5.8+) para el encabezado "Update URI":
 * WordPress no consulta wordpress.org por este plugin y en su lugar llama al
 * filtro update_plugins_github.com, donde respondemos con la última release.
 *
 * El mismo archivo se incluye en todos los plugins "another"; la versión va en
 * el nombre de la clase para que dos plugins con copias distintas no choquen.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Chilehalal_GitHub_Updater_V1' ) ) :

final class Chilehalal_GitHub_Updater_V1 {

    /** Tiempo que se guarda la respuesta de GitHub (la API anónima permite 60 consultas por hora). */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** Tiempo antes de reintentar si GitHub no respondió. */
    const ERROR_TTL = 30 * MINUTE_IN_SECONDS;

    /** @var string Archivo principal del plugin. */
    private $file;

    /** @var string Repositorio en formato "dueño/nombre". */
    private $repo;

    /** @var string Carpeta del plugin, que también es el nombre del zip de la release. */
    private $slug;

    /**
     * @param string $file Archivo principal del plugin (__FILE__).
     * @param string $repo Repositorio en formato "dueño/nombre".
     */
    private function __construct( $file, $repo ) {
        $this->file = $file;
        $this->repo = $repo;
        $this->slug = dirname( plugin_basename( $file ) );
    }

    /**
     * Engancha el actualizador para un plugin.
     *
     * @param string $file Archivo principal del plugin (__FILE__).
     * @param string $repo Repositorio en formato "dueño/nombre".
     * @return void
     */
    public static function register( $file, $repo ) {
        $updater = new self( $file, $repo );
        add_filter( 'update_plugins_github.com', [ $updater, 'check_update' ], 10, 3 );
        add_filter( 'plugins_api', [ $updater, 'plugin_info' ], 20, 3 );
        add_action( 'upgrader_process_complete', [ $updater, 'clear_cache' ], 10, 2 );
    }

    /**
     * Responde a WordPress con la última versión publicada.
     *
     * WordPress compara la versión y decide si mostrar la actualización.
     *
     * @param array|false $update      Respuesta de otro filtro, o false.
     * @param array       $plugin_data Encabezados del plugin.
     * @param string      $plugin_file Plugin que se está revisando.
     * @return array|false
     */
    public function check_update( $update, $plugin_data, $plugin_file ) {
        if ( plugin_basename( $this->file ) !== $plugin_file ) {
            return $update;
        }

        $release = $this->latest_release();
        if ( ! $release ) {
            return $update;
        }

        return [
            'slug'         => $this->slug,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires_php' => $plugin_data['RequiresPHP'] ?? '',
        ];
    }

    /**
     * Llena la ventana "Ver detalles" de la pantalla de plugins.
     *
     * @param false|object|array $result Resultado de otro filtro.
     * @param string             $action Acción solicitada.
     * @param object             $args   Argumentos, incluye el slug.
     * @return false|object|array
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->slug !== $args->slug ) {
            return $result;
        }

        $release = $this->latest_release();
        if ( ! $release ) {
            return $result;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data( $this->file, false, false );

        return (object) [
            'name'          => $data['Name'],
            'slug'          => $this->slug,
            'version'       => $release['version'],
            'author'        => $data['Author'],
            'homepage'      => 'https://github.com/' . $this->repo,
            'requires_php'  => $data['RequiresPHP'],
            'last_updated'  => $release['published'],
            'download_link' => $release['package'],
            'sections'      => [
                'description' => wpautop( esc_html( $data['Description'] ) ),
                'changelog'   => wpautop( esc_html( $release['notes'] ) ),
            ],
        ];
    }

    /**
     * Borra la respuesta guardada después de actualizar este plugin.
     *
     * @param WP_Upgrader $upgrader Instancia del actualizador.
     * @param array       $options  Datos de la actualización.
     * @return void
     */
    public function clear_cache( $upgrader, $options ) {
        if ( ( $options['type'] ?? '' ) === 'plugin' && in_array( plugin_basename( $this->file ), (array) ( $options['plugins'] ?? [] ), true ) ) {
            delete_site_transient( $this->cache_key() );
        }
    }

    /**
     * Última release con su zip, desde la cache o desde la API de GitHub.
     *
     * Solo cuenta una release si trae el archivo "<carpeta>.zip": el zip de
     * código fuente que GitHub genera solo cambia el nombre de la carpeta y
     * WordPress desactivaría el plugin al instalarlo.
     *
     * @return array|null Versión, página, zip, fecha y notas; null si no hay.
     */
    private function latest_release() {
        $cached = get_site_transient( $this->cache_key() );
        if ( is_array( $cached ) ) {
            return $cached['release'];
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $this->repo . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
                ],
            ]
        );

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // Sin releases todavía: GitHub responde 404 y no es un error.
        if ( 404 === $code ) {
            set_site_transient( $this->cache_key(), [ 'release' => null ], self::CACHE_TTL );
            return null;
        }

        if ( 200 !== $code || ! is_array( $body ) ) {
            set_site_transient( $this->cache_key(), [ 'release' => null ], self::ERROR_TTL );
            return null;
        }

        $package = '';
        foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
            if ( ( $asset['name'] ?? '' ) === $this->slug . '.zip' ) {
                $package = $asset['browser_download_url'];
                break;
            }
        }

        $release = null;
        if ( $package && ! empty( $body['tag_name'] ) ) {
            $release = [
                'version'   => ltrim( $body['tag_name'], 'vV' ),
                'url'       => $body['html_url'] ?? 'https://github.com/' . $this->repo,
                'package'   => $package,
                'published' => $body['published_at'] ?? '',
                'notes'     => $body['body'] ?? '',
            ];
        }

        set_site_transient( $this->cache_key(), [ 'release' => $release ], self::CACHE_TTL );
        return $release;
    }

    /**
     * @return string Clave de la cache de este plugin.
     */
    private function cache_key() {
        return 'chgu_' . md5( $this->repo );
    }
}

endif;
