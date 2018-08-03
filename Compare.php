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
			'status' => '',
			'left' => [
				'file' => $left_file,
				'exists' => false !== $left_contents,
				'commit' => [
					'file' => $left_file,
					'date' => '',
					'revision' => false === $left_contents ? '' : 'HEAD',
				],
				'next_commit' => [
					'file' => $left_file,
					'date' => '',
					'revision' => '',
				],
				'ahead' => 0,
			],
			'right' => [
				'file'     => $right_file,
				'exists'   => false !== $right_contents,
				'commit' => [
					'file' => $right_file,
					'date' => '',
					'revision' => false === $right_contents ? '' : 'HEAD',
				],
				'next_commit' => [
					'file' => $right_file,
					'date' => '',
					'revision' => '',
				],
				'ahead' => 0,
			]
		];

		if ( $return['left_exists'] xor $return['right_exists'] ) {
			$return['status'] = $return['left_exists'] ? 'new-in-left' : 'new-in-right';
		} else if ( rtrim( $left_contents, "\n" ) === rtrim( $right_contents, "\n" ) ) {
			$return['status'] = 'in-sync';
		} else {
			$ancestor = $this->find_common_ancestor( $left_file, $right_file );

			$return = array_replace_recursive( $return, $ancestor );

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
		$left_date = $right_date = '';

		$fast_cache = [];
		$original_fast_file = $fast_file;

		$fast_revisions = $fast->revisions_of_file( $fast_file );
		if ( ! is_array( $fast_revisions ) && is_iterable( $fast_revisions ) ) {
			// Convert to array so we can rewind for each loop in the slow_revisions foreach
			$fast_revisions = iterator_to_array( $fast_revisions );
		}

		$found_ancestor = false;
		$slow_ahead = $fast_ahead = 0; // How far ahead are we from our common ancestor
		$slow_next_commit = $fast_next_commit = false; // next chronologically (which is the previous commit in the loop)
		foreach ( $slow->revisions_of_file( $slow_file ) as $slow_commit ) {
			[ $slow_revision, $slow_date, $slow_file ] = $slow_commit;

			$slow_file_contents = $slow->get_file( $slow_file, $slow_revision );
			if ( false === $slow_file_contents ) {
				continue;
			}

			$slow_file_contents = rtrim( $slow_file_contents, "\n" );

			$fast_ahead = 0;
			$fast_file = $original_fast_file;
			$fast_next_commit = false;
			foreach ( $fast_revisions as $fast_commit ) {
				[ $fast_revision, $fast_date, $fast_file ] = $fast_commit;

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
					[ $left_revision, $right_revision, $left_date, $right_date, $left_next_commit, $right_next_commit ] = $slow === $this->left
						? [ $slow_revision, $fast_revision, $slow_date, $fast_date, $slow_next_commit, $fast_next_commit ]
						: [ $fast_revision, $slow_revision, $fast_date, $slow_date, $fast_next_commit, $slow_next_commit ];
					break 2;
				}

				$fast_next_commit = $fast_commit;
				$fast_ahead++;
			}

			$slow_next_commit = $slow_commit;
			$slow_ahead++;
		}

		list ( $left_ahead, $right_ahead, $left_file_old, $right_file_old ) = $slow === $this->left
			? [ $slow_ahead, $fast_ahead, $slow_file, $fast_file ]
			: [ $fast_ahead, $slow_ahead, $fast_file, $slow_file ];

		$left = [
			'commit' => [
				'file'     => $left_file_old,
				'date'     => $left_date,
				'revision' => $left_revision,
			],
			'ahead' => $left_ahead,
		];

		if ( $left_next_commit ) {
			$left['next_commit'] = [
				'file'     => $left_next_commit[2],
				'date'     => $left_next_commit[1],
				'revision' => $left_next_commit[0],
			];
		}

		$right = [
			'commit' => [
				'file'     => $right_file_old,
				'date'     => $right_date,
				'revision' => $right_revision,
			],
			'ahead' => $right_ahead,
		];

		if ( $right_next_commit ) {
			$right['next_commit'] = [
				'file'     => $right_next_commit[2],
				'date'     => $right_next_commit[1],
				'revision' => $right_next_commit[0],
			];
		}


		return compact(
			'found_ancestor',
			'left',
			'right'
		);
	}
}
