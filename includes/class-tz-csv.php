<?php
/**
 * CSV import pipeline.
 *
 * @package Techzapp_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses an uploaded CSV and imports it into tz_subscribers.
 *
 * Every row is filtered locally before it reaches the database. Sending to
 * addresses that never had a chance of resolving is the single fastest way to
 * destroy an SES reputation, so the cheap local checks happen first.
 */
class TZ_CSV {

	/** Hard ceiling on rows accepted from one upload. */
	const MAX_ROWS = 200000;

	/** Rows inserted per statement. */
	const INSERT_CHUNK = 200;

	/**
	 * Import a CSV from an uploaded file.
	 *
	 * @param array $file One entry from $_FILES.
	 * @return array|WP_Error Import statistics, or an error.
	 */
	public static function import_upload( array $file ) {
		$validated = self::validate_upload( $file );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return self::import_file( $validated );
	}

	/**
	 * Validate an uploaded file before touching its contents.
	 *
	 * @param array $file One entry from $_FILES.
	 * @return string|WP_Error Absolute path to the temp file, or an error.
	 */
	private static function validate_upload( array $file ) {
		if ( ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
			return new WP_Error( 'tz_bad_upload', __( 'No file was received. Please choose a CSV and try again.', 'tz-mailer' ) );
		}

		switch ( (int) $file['error'] ) {
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_NO_FILE:
				return new WP_Error( 'tz_no_file', __( 'No file was selected.', 'tz-mailer' ) );
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return new WP_Error(
					'tz_file_too_large',
					sprintf(
						/* translators: %s: server upload limit */
						__( 'That file exceeds the server upload limit (%s). Split the CSV into smaller files.', 'tz-mailer' ),
						size_format( wp_max_upload_size() )
					)
				);
			case UPLOAD_ERR_PARTIAL:
				return new WP_Error( 'tz_partial_upload', __( 'The upload was interrupted. Please try again.', 'tz-mailer' ) );
			default:
				return new WP_Error( 'tz_upload_failed', __( 'The upload failed. Check the server temp directory permissions.', 'tz-mailer' ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'tz_not_uploaded', __( 'The uploaded file could not be verified.', 'tz-mailer' ) );
		}

		if ( empty( $file['size'] ) ) {
			return new WP_Error( 'tz_empty_file', __( 'The uploaded file is empty.', 'tz-mailer' ) );
		}

		// Extension and MIME check. CSVs are reported with a frustrating
		// variety of MIME types depending on the client, so the extension is
		// authoritative and the MIME list is generous.
		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			return new WP_Error( 'tz_bad_extension', __( 'Only .csv (or plain .txt) files are accepted.', 'tz-mailer' ) );
		}

		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $name, self::allowed_mimes() );

		if ( empty( $checked['ext'] ) && empty( $checked['type'] ) ) {
			// Some hosts return nothing at all for text/* files. Fall back to
			// confirming the file is readable text rather than rejecting.
			$sample = file_get_contents( $file['tmp_name'], false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false === $sample || '' === $sample ) {
				return new WP_Error( 'tz_unreadable', __( 'The uploaded file could not be read.', 'tz-mailer' ) );
			}

			// Reject anything containing NUL bytes: that is a binary file.
			if ( false !== strpos( $sample, chr( 0 ) ) ) {
				return new WP_Error( 'tz_binary_file', __( 'That file is not plain text. Export your list as CSV and try again.', 'tz-mailer' ) );
			}
		}

		return $file['tmp_name'];
	}

	/**
	 * MIME types accepted for the CSV upload.
	 *
	 * @return array<string,string>
	 */
	private static function allowed_mimes() {
		return array(
			'csv' => 'text/csv',
			'txt' => 'text/plain',
		);
	}

	/**
	 * Parse and import an on-disk CSV.
	 *
	 * Column 1 is the email address, column 2 the name. A header row is
	 * detected automatically: if the first cell of the first row is not a
	 * valid email address, that row is treated as headings and skipped.
	 *
	 * @param string $path Absolute path to a readable CSV.
	 * @return array|WP_Error Import statistics.
	 */
	public static function import_file( $path ) {
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return new WP_Error( 'tz_open_failed', __( 'The CSV could not be opened for reading.', 'tz-mailer' ) );
		}

		// Long imports must not die halfway through and leave the operator
		// guessing which rows landed.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		wp_raise_memory_limit( 'admin' );

		$stats = array(
			'total_rows' => 0,
			'imported'   => 0,
			'duplicates' => 0,
			'invalid'    => 0,
			'skipped'    => 0,
			'header'     => false,
			'invalid_samples' => array(),
		);

		$delimiter = self::detect_delimiter( $path );
		$first     = true;
		$buffer    = array();
		$seen      = array();

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
			// fgetcsv returns array( null ) for a blank line.
			if ( null === $row || ( 1 === count( $row ) && ( null === $row[0] || '' === trim( (string) $row[0] ) ) ) ) {
				continue;
			}

			if ( $stats['total_rows'] >= self::MAX_ROWS ) {
				$stats['skipped']++;
				continue;
			}

			$raw_email = isset( $row[0] ) ? (string) $row[0] : '';
			$raw_name  = isset( $row[1] ) ? (string) $row[1] : '';

			if ( $first ) {
				$first = false;

				// Strip a UTF-8 BOM that Excel loves to prepend.
				$raw_email = preg_replace( '/^\xEF\xBB\xBF/', '', $raw_email );

				// Header detection: a heading row cannot be a valid address.
				if ( ! is_email( trim( $raw_email ) ) ) {
					$stats['header'] = true;
					continue;
				}
			}

			$stats['total_rows']++;

			$email = sanitize_email( trim( $raw_email ) );

			if ( ! is_email( $email ) ) {
				$stats['invalid']++;

				if ( count( $stats['invalid_samples'] ) < 10 && '' !== trim( $raw_email ) ) {
					$stats['invalid_samples'][] = sanitize_text_field( substr( trim( $raw_email ), 0, 80 ) );
				}

				continue;
			}

			// Addresses are case-insensitive for dedupe purposes; store the
			// lowercase form so the UNIQUE index actually catches duplicates.
			$email = strtolower( $email );

			if ( isset( $seen[ $email ] ) ) {
				$stats['duplicates']++;
				continue;
			}

			$seen[ $email ] = true;

			$buffer[] = array(
				'email' => $email,
				'name'  => self::clean_name( $raw_name ),
			);

			if ( count( $buffer ) >= self::INSERT_CHUNK ) {
				$stats = self::flush( $buffer, $stats );
				$buffer = array();
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! empty( $buffer ) ) {
			$stats = self::flush( $buffer, $stats );
		}


		TZ_Logger::log(
			'',
			'System',
			sprintf(
				'CSV import complete. Imported %d, duplicates skipped %d, invalid %d, rows over limit %d.',
				$stats['imported'],
				$stats['duplicates'],
				$stats['invalid'],
				$stats['skipped']
			)
		);

		return $stats;
	}

	/**
	 * Insert one buffered chunk and fold the outcome into the running stats.
	 *
	 * Rows already present in the database are counted as duplicates rather
	 * than silently vanishing, so the summary always adds up.
	 *
	 * @param array $buffer Rows pending insert.
	 * @param array $stats  Running statistics.
	 * @return array Updated statistics.
	 */
	private static function flush( array $buffer, array $stats ) {
		$emails   = wp_list_pluck( $buffer, 'email' );
		$existing = TZ_Subscribers::existing_emails( $emails );

		$fresh = array();

		foreach ( $buffer as $row ) {
			if ( isset( $existing[ $row['email'] ] ) ) {
				$stats['duplicates']++;
				continue;
			}

			$fresh[] = $row;
		}

		if ( ! empty( $fresh ) ) {
			$inserted = TZ_Subscribers::insert_batch( $fresh );

			$stats['imported'] += $inserted;

			// Anything the UNIQUE index rejected raced with another import.
			$stats['duplicates'] += max( 0, count( $fresh ) - $inserted );
		}

		return $stats;
	}

	/**
	 * Sniff the field delimiter from the first few lines.
	 *
	 * Exports from European locales commonly use semicolons, and some CRMs
	 * emit tab separated files with a .csv extension.
	 *
	 * @param string $path Absolute path to the CSV.
	 * @return string Detected delimiter.
	 */
	private static function detect_delimiter( $path ) {
		$candidates = array( ',', ';', "\t", '|' );
		$sample     = file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $sample || '' === $sample ) {
			return ',';
		}

		// Only look at complete lines.
		$lines = array_slice( preg_split( '/\r\n|\r|\n/', $sample ), 0, 5 );
		$best  = ',';
		$score = 0;

		foreach ( $candidates as $candidate ) {
			$count = 0;

			foreach ( $lines as $line ) {
				$count += substr_count( $line, $candidate );
			}

			if ( $count > $score ) {
				$score = $count;
				$best  = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Normalize a name cell.
	 *
	 * Trims wrapping quotes left by sloppy exports, strips control characters
	 * and clamps to the column width.
	 *
	 * @param string $raw Raw cell value.
	 * @return string
	 */
	private static function clean_name( $raw ) {
		$name = sanitize_text_field( trim( (string) $raw ) );
		$name = trim( $name, " '" . chr( 34 ) );

		// A name cell that is actually an email address is worse than nothing:
		// "Hi bob@example.com," reads like spam.
		if ( is_email( $name ) ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, 100 );
		}

		return substr( $name, 0, 100 );
	}

	/**
	 * Human readable summary of an import result.
	 *
	 * @param array $stats Output of import_file().
	 * @return string
	 */
	public static function summarize( array $stats ) {
		$parts = array();

		$parts[] = sprintf(
			/* translators: %s: number of contacts */
			_n( '%s contact imported.', '%s contacts imported.', $stats['imported'], 'tz-mailer' ),
			number_format_i18n( $stats['imported'] )
		);

		if ( $stats['duplicates'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of duplicates */
				_n( '%s duplicate skipped.', '%s duplicates skipped.', $stats['duplicates'], 'tz-mailer' ),
				number_format_i18n( $stats['duplicates'] )
			);
		}

		if ( $stats['invalid'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of invalid rows */
				_n( '%s invalid address rejected.', '%s invalid addresses rejected.', $stats['invalid'], 'tz-mailer' ),
				number_format_i18n( $stats['invalid'] )
			);
		}

		if ( $stats['skipped'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: row limit */
				__( 'Rows beyond the %s row per-file limit were ignored.', 'tz-mailer' ),
				number_format_i18n( self::MAX_ROWS )
			);
		}

		if ( ! empty( $stats['header'] ) ) {
			$parts[] = __( 'A header row was detected and skipped.', 'tz-mailer' );
		}

		return implode( ' ', $parts );
	}
}
