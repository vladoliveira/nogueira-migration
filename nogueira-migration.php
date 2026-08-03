<?php
/**
 * Plugin Name: Nogueira Brindes Migration
 * Description: Migração de produtos do sistema legado para WooCommerce via WP-CLI.
 * Version: 1.0.0
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Nogueira_Migration_Command {

	private $legacy_db;

	private $base_url;

	private $dry_run = false;

	private $skip_images = false;

	private $term_cache = array();

	private $image_cache = array();


	/**
	 * Migra produtos do banco legado.
	 *
	 * ## OPTIONS
	 *
	 * --host=<host>
	 * : Servidor MySQL.
	 *
	 * --mysql-user=<user>
	 * : Usuário MySQL do banco legado.
	 *
	 * --mysql-pass=<pass>
	 * : Senha MySQL.
	 *
	 * --db=<database>
	 * : Banco legado.
	 *
	 * [--base-url=<url>]
	 * : URL antiga para imagens.
	 *
	 * [--limit=<n>]
	 * : Quantidade por lote.
	 *
	 * [--offset=<n>]
	 * : Offset.
	 *
	 * [--dry-run]
	 * : Simula sem gravar.
	 *
	 * [--skip-images]
	 * : Não importa imagens.
	 *
	 * @when after_wp_load
	 */
	public function migrate( $args, $assoc_args ) {

		$host = $assoc_args['host'] ?? 'localhost';

		$user = $assoc_args['mysql-user'] ?? '';

		$pass = $assoc_args['mysql-pass'] ?? '';

		$db = $assoc_args['db'] ?? '';

		if ( empty( $user ) ) {
			WP_CLI::error(
				'Informe --mysql-user'
			);
		}

		if ( empty( $pass ) ) {
			WP_CLI::error(
				'Informe --mysql-pass'
			);
		}

		if ( empty( $db ) ) {
			WP_CLI::error(
				'Informe --db'
			);
		}

		$this->base_url =
			rtrim(
				$assoc_args['base-url']
				?? 'https://www.nogueirabrindes.com.br',
				'/'
			);

		$this->dry_run =
			isset(
				$assoc_args['dry-run']
			);

		$this->skip_images =
			isset(
				$assoc_args['skip-images']
			);


		$this->legacy_db = new mysqli(
			$host,
			$user,
			$pass,
			$db
		);


		if ( $this->legacy_db->connect_error ) {

			WP_CLI::error(
				$this->legacy_db->connect_error
			);

		}


		$this->legacy_db->set_charset(
			'utf8mb4'
		);


		$limit =
			isset( $assoc_args['limit'] )
			? intval( $assoc_args['limit'] )
			: 0;


		$offset =
			isset( $assoc_args['offset'] )
			? intval( $assoc_args['offset'] )
			: 0;


		$sql =
			"SELECT * FROM produtos ORDER BY codigo ASC";


		if ( $limit > 0 ) {

			$sql .=
				" LIMIT {$limit} OFFSET {$offset}";

		}


		$result =
			$this->legacy_db->query(
				$sql
			);


		if ( ! $result ) {

			WP_CLI::error(
				$this->legacy_db->error
			);

		}


		$total =
			$result->num_rows;


		WP_CLI::log(
			"Produtos encontrados: {$total}"
		);


		$progress =
			\WP_CLI\Utils::make_progress_bar(
				'Migrando produtos',
				$total
			);


		$created = 0;
		$updated = 0;
		$errors = 0;
    		while ( $row = $result->fetch_assoc() ) {

			try {

				$status = $this->migrate_product( $row );

				if ( 'created' === $status ) {

					$created++;

				} else {

					$updated++;

				}

			} catch ( Exception $e ) {

				$errors++;

				WP_CLI::warning(
					"Produto {$row['codigo']}: " .
					$e->getMessage()
				);

			}

			$progress->tick();

		}


		$progress->finish();


		WP_CLI::success(
			sprintf(
				"Finalizado. Criados: %d | Atualizados: %d | Erros: %d",
				$created,
				$updated,
				$errors
			)
		);

	}



	private function migrate_product( $row ) {


		$legacy_id = intval(
			$row['codigo']
		);


		$existing = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'meta_key'       => '_legacy_product_id',
				'meta_value'     => $legacy_id,
				'numberposts'    => 1,
				'fields'         => 'ids',
			)
		);


		$post_id = ! empty( $existing )
			? intval( $existing[0] )
			: 0;


		$title =
			stripslashes(
				$row['nome']
			);


		$slug =
			! empty( $row['url'] )
			? sanitize_title(
				$row['url']
			)
			: sanitize_title(
				$title
			);


		if ( $this->dry_run ) {

			WP_CLI::log(
				(
					$post_id
					? '[ATUALIZAR] '
					: '[CRIAR] '
				)
				.
				$title
			);


			return $post_id
				? 'updated'
				: 'created';

		}


		$post_data = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' =>
				stripslashes(
					$row['descricao']
				),
		);



		if ( $post_id ) {


			$post_data['ID'] = $post_id;


			wp_update_post(
				$post_data
			);


			$action = 'updated';


		} else {


			$post_id =
				wp_insert_post(
					$post_data,
					true
				);


			if ( is_wp_error( $post_id ) ) {

				throw new Exception(
					$post_id->get_error_message()
				);

			}


			update_post_meta(
				$post_id,
				'_legacy_product_id',
				$legacy_id
			);


			$action = 'created';

		}



		wp_set_object_terms(
			$post_id,
			'simple',
			'product_type'
		);



		if ( ! empty( $row['cod_produto'] ) ) {

			update_post_meta(
				$post_id,
				'_sku',
				stripslashes(
					$row['cod_produto']
				)
			);

		}



		$esgotado =
			isset( $row['status'] )
			&& intval( $row['status'] ) === 2;



		update_post_meta(
			$post_id,
			'_stock_status',
			$esgotado
			? 'outofstock'
			: 'instock'
		);


		update_post_meta(
			$post_id,
			'_manage_stock',
			'no'
		);



		$this->set_acf_field(
			'produto_oculto',
			(bool) $row['oculto'],
			$post_id
		);


		$this->set_acf_field(
			'esgotado_',
			$esgotado,
			$post_id
		);


		$this->set_acf_field(
			'cores_',
			stripslashes(
				$row['cores']
			),
			$post_id
		);


		$this->set_acf_field(
			'qtde_',
			$row['qtde'],
			$post_id
		);



		if (
			! empty(
				$row['urlvideoytube']
			)
		) {

			$this->set_acf_field(
				'url_do_video',
				$row['urlvideoytube'],
				$post_id
			);

		}
    		$this->assign_categories(
			$post_id,
			$row['idcategoria'] ?? '',
			$row['idsubcategoria'] ?? ''
		);


		if ( ! $this->skip_images ) {

			$this->assign_images(
				$post_id,
				$legacy_id,
				$title
			);

		}


		return $action;

	}



	private function set_acf_field(
		$field,
		$value,
		$post_id
	) {


		if ( function_exists( 'update_field' ) ) {


			update_field(
				$field,
				$value,
				$post_id
			);


		} else {


			update_post_meta(
				$post_id,
				$field,
				$value
			);


		}

	}




	private function parse_ids(
		$value
	) {


		$value =
			trim(
				(string) $value,
				'|'
			);


		if ( empty( $value ) ) {

			return array();

		}


		return array_filter(
			array_map(
				'intval',
				explode(
					'|',
					$value
				)
			)
		);

	}




	private function assign_categories(
		$post_id,
		$idcategoria,
		$idsubcategoria
	) {


		$categorias =
			$this->parse_ids(
				$idcategoria
			);


		$subcategorias =
			$this->parse_ids(
				$idsubcategoria
			);



		$terms = array();



		foreach ( $categorias as $cat_id ) {



			$categoria =
				$this->fetch_legacy_row(
					'produtos_categorias',
					'codigo',
					$cat_id
				);



			if ( ! $categoria ) {

				continue;

			}



			$nome =
				! empty( $categoria['nome_2'] )
				? $categoria['nome_2']
				: $categoria['nome'];



			$parent =
				$this->get_or_create_term(
					$nome,
					0
				);



			if ( $parent ) {

				$terms[] = $parent;

			}




			foreach ( $subcategorias as $sub_id ) {



				$sub =
					$this->fetch_legacy_row(
						'produtos_subcategorias',
						'codigo',
						$sub_id
					);



				if ( ! $sub ) {

					continue;

				}



				if (
					intval(
						$sub['idcategoria']
					)
					!== intval( $cat_id )
				) {

					continue;

				}



				$sub_nome =
					! empty( $sub['nome_2'] )
					? $sub['nome_2']
					: $sub['nome'];



				$child =
					$this->get_or_create_term(
						$sub_nome,
						$parent
					);



				if ( $child ) {

					$terms[] = $child;

				}


			}


		}



		if ( ! empty( $terms ) ) {


			wp_set_object_terms(
				$post_id,
				array_unique( $terms ),
				'product_cat'
			);


		}


	}
  	private function get_or_create_term(
		$name,
		$parent = 0
	) {


		$name = trim(
			stripslashes(
				$name
			)
		);


		if ( empty( $name ) ) {

			return 0;

		}



		$key =
			md5(
				$name . '|' . $parent
			);



		if (
			isset(
				$this->term_cache[$key]
			)
		) {

			return $this->term_cache[$key];

		}



		$exists =
			term_exists(
				$name,
				'product_cat',
				$parent
			);



		if ( $exists ) {


			$id =
				intval(
					$exists['term_id']
				);



		} else {



			$new =
				wp_insert_term(
					$name,
					'product_cat',
					array(
						'parent' => $parent,
					)
				);



			if ( is_wp_error( $new ) ) {


				WP_CLI::warning(
					$new->get_error_message()
				);


				return 0;


			}



			$id =
				intval(
					$new['term_id']
				);



		}



		$this->term_cache[$key] = $id;



		return $id;


	}





	private function fetch_legacy_row(
		$table,
		$field,
		$value
	) {


		$value =
			intval(
				$value
			);



		$sql =
			"SELECT * FROM {$table}
			 WHERE {$field} = ?
			 LIMIT 1";



		$stmt =
			$this->legacy_db->prepare(
				$sql
			);



		if ( ! $stmt ) {

			return false;

		}



		$stmt->bind_param(
			'i',
			$value
		);



		$stmt->execute();



		$result =
			$stmt->get_result();



		if ( ! $result ) {

			return false;

		}



		return $result->fetch_assoc();


	}





	private function assign_images(
		$post_id,
		$legacy_id,
		$title
	) {


		$stmt =
			$this->legacy_db->prepare(
				"SELECT imagem
				 FROM produtos_imagens
				 WHERE idproduto = ?
				 ORDER BY ordem ASC, codigo ASC"
			);



		if ( ! $stmt ) {

			return;

		}



		$stmt->bind_param(
			'i',
			$legacy_id
		);



		$stmt->execute();



		$result =
			$stmt->get_result();



		$images = array();



		while (
			$row =
			$result->fetch_assoc()
		) {


			if (
				! empty(
					$row['imagem']
				)
			) {


				$images[] =
					$row['imagem'];


			}


		}



		if ( empty( $images ) ) {

			return;

		}



		$attachments = array();



		foreach ( $images as $image ) {



			$url =
				$this->base_url .
				'/upload/' .
				rawurlencode(
					$image
				);



			$id =
				$this->sideload_image(
					$url,
					$post_id,
					$title
				);



			if ( $id ) {

				$attachments[] = $id;

			}


		}



		if ( empty( $attachments ) ) {

			return;

		}



		$featured =
			array_shift(
				$attachments
			);



		set_post_thumbnail(
			$post_id,
			$featured
		);



		if ( ! empty( $attachments ) ) {


			update_post_meta(
				$post_id,
				'_product_image_gallery',
				implode(
					',',
					$attachments
				)
			);


		}


	}
  	private function sideload_image(
		$url,
		$post_id,
		$description = ''
	) {


		if (
			isset(
				$this->image_cache[$url]
			)
		) {

			return $this->image_cache[$url];

		}



		require_once ABSPATH .
			'wp-admin/includes/file.php';

		require_once ABSPATH .
			'wp-admin/includes/media.php';

		require_once ABSPATH .
			'wp-admin/includes/image.php';




		$attachment_id =
			media_sideload_image(
				$url,
				$post_id,
				$description,
				'id'
			);



		if (
			is_wp_error(
				$attachment_id
			)
		) {


			WP_CLI::warning(
				'Falha imagem: ' .
				$url .
				' - ' .
				$attachment_id->get_error_message()
			);



			return 0;


		}



		$this->image_cache[$url] =
			intval(
				$attachment_id
			);



		return intval(
			$attachment_id
		);


	}



}
WP_CLI::add_command(
	'nogueira',
	'Nogueira_Migration_Command'
);
