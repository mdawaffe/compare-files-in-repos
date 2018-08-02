<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos\Transform;

class RemoveSecretComments extends \Compare_Files_In_Repos\Transform {
	private $tag = '';

	public function __construct( string $tag ) {
		parent::__construct();

		$this->tag = $tag;
	}

	private function php( string $file_contents, string $file_path ) : string {
		$output = '';

		$tag = "@{$this->tag}";

		$whitespace = '';
		$i_just_removed_a_secret_comment = false;

		$tokens = token_get_all( $file_contents );
		foreach ( $tokens as $token ) {
			if ( is_string( $token ) ) {
				$output .= $whitespace . $token;
				$whitespace = '';
				continue;
			}

			switch ( $token[0] ) {
			case T_WHITESPACE :
				if ( $i_just_removed_a_secret_comment ) {
					$i_just_removed_a_secret_comment = false;
					$whitespace .= preg_replace( '/^(?:\n|\r\n|\r)/', '', $token[1] );
				} else {
					$whitespace .= $token[1];
				}
				break;
			case T_COMMENT :
			case T_DOC_COMMENT :
				if ( false !== stripos( $token[1], $tag ) ) {
					$this->logger->debug( sprintf( 'Removing %s from %s', $tag, $file_path ) );
					$whitespace = rtrim( $whitespace, " \t" );
					$i_just_removed_a_secret_comment = true;
					break;
				}
				// no break
			default :
				$output .= $whitespace . $token[1];
				$whitespace = '';
			}
		}

		$output .= $whitespace;

		return $output;
	}

	private function js( string $file_contents, string $file_path ) : string {
		$output = '';

		$tag = "@{$this->tag}";
		$start_tag = "@start-{$this->tag}";
		$end_tag = "@end-{$this->tag}";

		$hide_block_started = false;

		$line_starts_at = 0;
		$line_ends_at = strpos( $file_contents, "\n" );
		$file_length = strlen( $file_contents );

		while ( false !== $line_ends_at ) {
			$line = substr( $file_contents, $line_starts_at, $line_ends_at + 1 - $line_starts_at );
			if ( $hide_block_started ) {
				if ( false !== stripos( $line, $end_tag ) ) {
					$hide_block_started = false;
				}
			} elseif ( false !== stripos( $line, $start_tag ) ) {
				$this->logger->debug( sprintf( 'Removing %s--%s from %s', $start_tag, $end_tag, $file_path ) );
				$hide_block_started = true;
			} elseif ( false !== stripos( $line, $tag ) ) {
				$this->logger->debug( sprintf( 'Removing %s from %s', $tag, $file_path ) );
			} else {
				$output .= $line;
			}

			$line_starts_at = $line_ends_at + 1;
			$line_end_at = strpos( $file_contents, "\n", $line_end_at + 1 );
		}
	}

	public function transform( string $file_contents, string $file_path ) : string {
		$extension = array_slice( explode( '.', $file_path ), -1 )[0] ?? false;

		switch ( $extension ) {
		case 'php' :
			return $this->php( $file_contents, $file_path );
		case 'js' :
			return $this->js( $file_contents, $file_path );
		}

		return $file_contents;
	}
}
