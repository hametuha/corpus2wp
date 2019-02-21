<?php

namespace Hametuha\Corpus2WP;

use Sunra\PhpSimple\HtmlDomParser;

/**
 * Parse Aozora Bunko to get japanese corpus.
 *
 * @package Hametuha\Corpus2WP
 */
class Aozora extends \WP_CLI {

	const ENDPOINT = 'https://www.aozora.gr.jp/index_pages/list_person_all_extended_utf8.zip';

	/**
	 * Get works list.
	 *
	 *
	 *
	 * @return \SplFileObject|\WP_Error
	 */
	protected function get_csv() {
		// Download CSV.
		$response = wp_remote_get( self::ENDPOINT );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		// Unpack.
		$dir = tempnam( sys_get_temp_dir(), 'aozora-' );
		unlink( $dir ); // Remove temp file to use it as name.
		if( ! mkdir( $dir, 0755, true ) ) {
			return new \WP_Error( 'save_failed', sprintf( 'Failed to create new directory %s', $dir ) );
		}
		$zip = $dir . '/aozora.zip';
		$csv = $dir . '/list_person_all_extended_utf8.csv';
		if ( ! file_put_contents( $zip, $response['body'] ) ) {
			return new \WP_Error( 'save_failed', 'Failed to save downloaded data.' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'requirements_unsatisfied', 'no ZipArchive' );
		}
		$archive = new \ZipArchive();
		if ( ! $archive->open( $zip ) ) {
			return new \WP_Error( 'zip_failed', 'Failed to open zip file.' );
		}
		$archive->extractTo( $dir );
		$archive->close();
		if ( ! file_exists( $csv ) ) {
			return new \WP_Error( 'zip_failed', 'Failed to unpack archive.' );
		}
		// Load CSV.
		$reader = new \SplFileObject( $csv );
		$reader->setFlags( \SplFileObject::READ_CSV );
		return $reader;
	}

	/**
	 * @param $row
	 *
	 * @return \WP_Error|true
	 */
	protected function handle_row( $row ) {
		try {
			//    [0] => 作品ID
			//    [1] => 作品名
			//    [2] => 作品名読み
			//    [3] => ソート用読み
			//    [4] => 副題
			//    [5] => 副題読み
			//    [6] => 原題
			//    [7] => 初出
			//    [8] => 分類番号
			//    [9] => 文字遣い種別
			//    [10] => 作品著作権フラグ
			//    [11] => 公開日
			//    [12] => 最終更新日
			//    [13] => 図書カードURL
			//    [14] => 人物ID
			//    [15] => 姓
			//    [16] => 名
			//    [17] => 姓読み
			//    [18] => 名読み
			//    [19] => 姓読みソート用
			//    [20] => 名読みソート用
			//    [21] => 姓ローマ字
			//    [22] => 名ローマ字
			//    [23] => 役割フラグ
			//    [24] => 生年月日
			//    [25] => 没年月日
			//    [26] => 人物著作権フラグ
			//    [27] => 底本名1
			//    [28] => 底本出版社名1
			//    [29] => 底本初版発行年1
			//    [30] => 入力に使用した版1
			//    [31] => 校正に使用した版1
			//    [32] => 底本の親本名1
			//    [33] => 底本の親本出版社名1
			//    [34] => 底本の親本初版発行年1
			//    [35] => 底本名2
			//    [36] => 底本出版社名2
			//    [37] => 底本初版発行年2
			//    [38] => 入力に使用した版2
			//    [39] => 校正に使用した版2
			//    [40] => 底本の親本名2
			//    [41] => 底本の親本出版社名2
			//    [42] => 底本の親本初版発行年2
			//    [43] => 入力者
			//    [44] => 校正者
			//    [45] => テキストファイルURL
			//    [46] => テキストファイル最終更新日
			//    [47] => テキストファイル符号化方式
			//    [48] => テキストファイル文字集合
			//    [49] => テキストファイル修正回数
			//    [50] => XHTML/HTMLファイルURL
			//    [51] => XHTML/HTMLファイル最終更新日
			//    [52] => XHTML/HTMLファイル符号化方式
			//    [53] => XHTML/HTMLファイル文字集合
			//    [54] => XHTML/HTMLファイル修正回数
			$id  = $row[0];
			$url = $row[50];
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$html = $response['body'];
			if ( 'ShiftJIS' === $row[52] ) {
				$html = mb_convert_encoding( $html, 'utf-8', 'sjis-win' );
			}
			$dom = HtmlDomParser::str_get_html( $html );
			if ( ! $dom || ! ( $main = $dom->find( '.main_text', 0 ) ) ) {
				throw new \Exception( sprintf( 'Failed to parse DOM of %s', $id ) );
			}
			$post_arg = [
				'post_title'  => $row[1],
				'post_status' => 'publish',
				'post_date'   => $row[11] . ' 00:00:00',
				'post_type'  => 'post',
				'post_content' => strip_tags( preg_replace( '#<(br|br/|br /)>#u', "\n", $main->innertext ), '<ruby><rp><rt>' ),
			];
			if ( $post_id = $this->get_post_id_from_aozora_id( $id ) ) {
				$post_arg['ID'] = $post_id;
				if ( ! wp_update_post( $post_arg ) ) {
					throw new \Exception( 'Failed to update post.', $id );
				}
			} elseif ( ! ( $post_id = wp_insert_post( $post_arg ) ) ) {
				throw new \Exception( 'Failed to insert post.', $id );
			}
			$author = "{$row[15]} {$row[16]}";
			wp_set_object_terms( $post_id, [ $row[8] ], 'category' );
			wp_set_object_terms( $post_id, [ $author ], 'post_tag' );
			$metas = [
				'_input_ty'   => $row[43],
				'_revised_ty' => $row[44],
			];
			foreach ( $metas as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
			return true;
		} catch ( \Exception $e ) {
			new \WP_Error( 'import_failed', $e->getMessage() );
		}
	}

	/**
	 * Get post id from aozora bunko ID.
	 *
	 * @param int $id
	 *
	 * @return int
	 */
	protected function get_post_id_from_aozora_id( $id ) {
		global $wpdb;
		$query = <<<SQL
			SELECT post_id FROM {$wpdb->postmeta}
			WHERE meta_key   = '_aozora_bunko_id'
			  AND meta_value = %s
			LIMIT 1
SQL;
		return (int) $wpdb->get_var( $wpdb->prepare( $query, $id ) );
	}

	/**
	 * Import all corpus.
	 *
	 * @synopsis [--skip=<skip>]
	 * @param array $args
	 * @param array $assoc
	 */
	public function import( $args, $assoc ) {
		$csv = $this->get_csv();
		if ( is_wp_error( $csv ) ) {
			\WP_CLI::error( $csv->get_error_message() );
		}
		$sleep  = 2;
		$row_count = -1;
		$success = 0;
		$error   = 0;
		$skip    = isset( $assoc['skip'] ) ? $assoc['skip'] : 0;
		foreach ( $csv as $row ) {
			$row_count++;
			// Skip row header.
			if ($row_count <= $skip || ! $row_count || 55 !== count( $row ) ) {
				continue;
			}
			$result = $this->handle_row( $row );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $result->get_error_message() );
			} else {
				$success++;
			}
			if ( 0 === $row_count % 10 ) {
				sleep( $sleep );
				echo '.';
			}
		}
		\WP_CLI::success( sprintf( '%s posts are imported.', number_format( $row_count ) ) );
	}
}
