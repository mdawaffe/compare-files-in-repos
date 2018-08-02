<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

class Compare {
	private $left;
	private $right;

	public function __construct( namespace\Repo $left, namespace\Repo $right ) {
		$this->left = $left;
		$this->right = $right;
	}

	public function compare( array $files ) {
		return array_filter( array_map( [ $this, 'compare_file' ], array_keys( $files ), array_values( $files ) ) );
	}

	public function compare_file( $left_file, $right_file ) {
		if ( $this->left->is_ignored( $left_file ) ) {
			return false;
		}

		if ( $this->right->is_ignored( $right_file ) ) {
			return false;
		}

		// @todo is_dir

		$left_contents = $this->left->get_file( $left_file );
		$right_contents = $this->right->get_file( $right_file );

		$return = [
			'left_file'      => $left_file,
			'right_file'     => $right_file,
			'left_file_old'  => $left_file,
			'right_file_old' => $right_file,
			'left_exists'    => false !== $left_contents,
			'right_exists'   => false !== $right_contents,
			'status'         => '',
			'left_revision'  => false === $left_contents ? '' : 'HEAD',
			'right_revision' => false === $right_contents ? '' : 'HEAD',
			'left_ahead'     => 0,
			'right_ahead'    => 0,
		];

		if ( $return['left_exists'] xor $return['right_exists'] ) {
			$return['status'] = $return['left_exists'] ? 'new-in-left' : 'new-in-right';
		} else if ( rtrim( $left_contents, "\n" ) === rtrim( $right_contents, "\n" ) ) {
			$return['status'] = 'in-sync';
		} else {
			$ancestor = $this->find_common_ancestor( $left_file, $right_file );

			$return = array_merge( $return, $ancestor );

			if ( $ancestor['found_ancestor'] ) {
				if ( 0 === $ancestor['left_ahead'] ) {
					$return['status'] = 'right-ahead';
				} else if ( 0 === $ancestor['right_ahead'] ) {
					$return['status'] = 'left-ahead';
				} else {
					$return['status'] = 'divergent';
				}
			} else {
				$return['status'] = 'no-common-ancestor';
			}

			unset( $return['found_ancestor'] );
		}

		return $return;
	}

	private function get_slow_fast_repos( string $left_file, string $right_file ) : array {
		// They could both be slow (or both fast)
		// but there's not much we can do in that situation
		if ( $this->left->is_slow() ) {
			return [ $this->left, $left_file, $this->right, $right_file ];
		} else {
			return [ $this->right, $right_file, $this->left, $left_file ];
		}
	}

	public function find_common_ancestor( string $left_file, string $right_file ) {
		[ $slow, $slow_file, $fast, $fast_file ] = $this->get_slow_fast_repos( $left_file, $right_file );

		$left_revision = $right_revision = false;

		$fast_cache = [];
		$original_fast_file = $fast_file;

		$found_ancestor = false;
		$slow_ahead = $fast_ahead = 0; // How far ahead are we from our common ancestor
		foreach ( $slow->revisions_of_file( $slow_file ) as [ $slow_revision, $slow_file ] ) {
			$slow_file_contents = $slow->get_file( $slow_file, $slow_revision );
			if ( false === $slow_file_contents ) {
				continue;
			}

			$slow_file_contents = rtrim( $slow_file_contents, "\n" );

			$fast_ahead = 0;
			$fast_file = $original_fast_file;
			foreach ( $fast->revisions_of_file( $fast_file ) as [ $fast_revision, $fast_file ] ) {
				if ( ! isset( $fast_file_cache[$fast_revision] ) ) {
					$fast_file_cache[$fast_revision] = $fast->get_file( $fast_file, $fast_revision );
				}

				$fast_file_contents = $fast_file_cache[$fast_revision];
				if ( false === $fast_file_contents ) {
					continue;
				}

				$fast_file_contents = rtrim( $fast_file_contents, "\n" );

				if ( $slow_file_contents === $fast_file_contents ) {
					$found_ancestor = true;
					list ( $left_revision, $right_revision ) = $slow === $this->left
						? [ $slow_revision, $fast_revision ]
						: [ $fast_revision, $slow_revision ];
					break 2;
				}

				$fast_ahead++;
			}

			$slow_ahead++;
		}

		list ( $left_ahead, $right_ahead, $left_file_old, $right_file_old ) = $slow === $this->left
			? [ $slow_ahead, $fast_ahead, $slow_file, $fast_file ]
			: [ $fast_ahead, $slow_ahead, $fast_file, $slow_file ];

		return compact( 'found_ancestor', 'left_revision', 'right_revision', 'left_ahead', 'right_ahead', 'left_file_old', 'right_file_old' );
	}
}
