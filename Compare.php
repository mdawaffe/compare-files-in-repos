<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

class Compare {
	use \Psr\Log\LoggerAwareTrait;

	private $left;
	private $right;

	public function __construct( namespace\Repo $left, namespace\Repo $right ) {
		$this->logger = new \Psr\Log\NullLogger;

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

		$this->logger->info( sprintf( "Comparing %s and %s", $left_file, $right_file ) );

		$return = [
			'status' => '',
			'left' => [
				'file' => $left_file,
				'exists' => false !== $left_contents,
				'ancestor' => [
					'file' => $left_file,
					'date' => '',
					'revision' => false === $left_contents ? '' : 'HEAD',
				],
				'subsequent_commits' => [],
			],
			'right' => [
				'file'     => $right_file,
				'exists'   => false !== $right_contents,
				'ancestor' => [
					'file' => $right_file,
					'date' => '',
					'revision' => false === $right_contents ? '' : 'HEAD',
				],
				'subsequent_commits' => [],
			]
		];

		if ( $return['left']['exists'] xor $return['right']['exists'] ) {
			if ( $return['left']['exists'] ) {
				$this->logger->info( sprintf( '	%s is new', $left_file ) );
				$return['status'] = 'new-in-left';
			} else {
				$this->logger->info( sprintf( '	%s is new', $right_file ) );
				$return['status'] = 'new-in-right';
			}
		} else if ( rtrim( $left_contents, "\n" ) === rtrim( $right_contents, "\n" ) ) {
			$this->logger->info( sprintf( '	%s and %s are in sync', $left_file, $right_file ) );
			$return['status'] = 'in-sync';
		} else {
			$this->logger->info( '	Checking for common ancestor...' );
			$ancestor = $this->find_common_ancestor( $left_file, $right_file );

			$return = array_replace_recursive( $return, $ancestor );

			if ( $ancestor['found_ancestor'] ) {
				$left_label = $left_file === $ancestor['left']['ancestor']['file']
					? $left_file
					: sprintf( '%s(%s)', $ancestor['left']['ancestor']['file'], $left_file );
				$right_label = $right_file === $ancestor['right']['ancestor']['file']
					? $right_file
					: sprintf( '%s(%s)', $ancestor['right']['ancestor']['file'], $right_file );

				$this->logger->info( sprintf(
					'	Found common ancestor: %s@%s = %s@%s',
					$left_label,
					$ancestor['left']['ancestor']['revision'],
					$right_label,
					$ancestor['right']['ancestor']['revision']
				) );

				if ( 0 === count( $ancestor['left']['subsequent_commits'] ) ) {
					$this->logger->info( sprintf(
						'	Since then, %s has changed in %d commits',
						$right_label,
						count( $ancestor['right']['subsequent_commits'] )
					) );
					$return['status'] = 'right-ahead';
				} else if ( 0 === count( $ancestor['right']['subsequent_commits'] ) ) {
					$this->logger->info( sprintf(
						'	Since then, %s has changed in %d commits',
						$left_label,
						count( $ancestor['left']['subsequent_commits'] )
					) );
					$return['status'] = 'left-ahead';
				} else {
					$this->logger->info( sprintf(
						'	Since then, %s has changed in %d commits and %s has changed in %d commits',
						$left_label,
						count( $ancestor['left']['subsequent_commits'] ),
						$right_label,
						count( $ancestor['right']['subsequent_commits'] )
					) );
					$return['status'] = 'divergent';
				}
			} else {
				$this->logger->info( '	No common ancestor found' );
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
		$slow_subsequent_commits = []; // Commits after (later in time - earlier in the loop) than the common ancestor
		foreach ( $slow->revisions_of_file( $slow_file ) as $slow_commit ) {
			[ $slow_revision, $slow_date, $slow_file ] = $slow_commit;
			$this->logger->info( sprintf(
				'		Checking %s@%s against all %s commits...',
				$slow_file,
				$slow_revision,
				$fast_file
			) );

			$slow_file_contents = $slow->get_file( $slow_file, $slow_revision );
			if ( false === $slow_file_contents ) {
				continue;
			}

			$slow_file_contents = rtrim( $slow_file_contents, "\n" );

			$fast_subsequent_commits = [];
			$fast_file = $original_fast_file;
			foreach ( $fast_revisions as $fast_commit ) {
				[ $fast_revision, $fast_date, $fast_file ] = $fast_commit;
				$this->logger->info( sprintf(
					'		- %s@%s',
					$fast_file,
					$fast_revision
				) );

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
					[ $left_revision, $right_revision, $left_date, $right_date ] = $slow === $this->left
						? [ $slow_revision, $fast_revision, $slow_date, $fast_date ]
						: [ $fast_revision, $slow_revision, $fast_date, $slow_date ];
					break 2;
				}

				$fast_subsequent_commits[] = [
					'file'     => $fast_commit[2],
					'date'     => $fast_commit[1],
					'revision' => $fast_commit[0],
				];
			}

			$slow_subsequent_commits[] = [
				'file'     => $slow_commit[2],
				'date'     => $slow_commit[1],
				'revision' => $slow_commit[0],
			];
		}

		[ $left_file_old, $right_file_old, $left_subsequent_commits, $right_subsequent_commits ] = $slow === $this->left
			? [ $slow_file, $fast_file, $slow_subsequent_commits, $fast_subsequent_commits ]
			: [ $fast_file, $slow_file, $fast_subsequent_commits, $slow_subsequent_commits ];

		$left = [
			'ancestor' => [
				'file'     => $left_file_old,
				'date'     => $left_date,
				'revision' => $left_revision,
			],
			'subsequent_commits' => $left_subsequent_commits ?? [],
		];

		$right = [
			'ancestor' => [
				'file'     => $right_file_old,
				'date'     => $right_date,
				'revision' => $right_revision,
			],
			'subsequent_commits' => $right_subsequent_commits ?? [],
		];

		return compact(
			'found_ancestor',
			'left',
			'right'
		);
	}
}
