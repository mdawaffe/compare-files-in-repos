<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

require __DIR__ . '/Repo.php';
require __DIR__ . '/Transform.php';

class Compare {
	private $left;
	private $right;

	public function __construct( Repo $left, Repo $right ) {
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
			'left_exists' => false !== $left_contents,
			'right_exists' => false !== $right_contents,
			'status' => '',
		];

		if ( $return['left_exists'] xor $return['right_exists'] ) {
			$return['status'] = $return['left_exists'] ? 'new-in-left' : 'new-in-right';
		} else if ( rtrim( $left_contents, "\n" ) === rtrim( $right_contents, "\n" ) ) {
			$return['status'] = 'in-sync';
		} else {
			$ancestor = $this->find_common_ancestor( $left_file, $right_file );
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
		list( $slow, $slow_file, $fast, $fast_file ) = $this->get_slow_fast_repos( $left_file, $right_file );

		foreach ( $slow->revisions_of_file( $slow_file ) as $slow_revision ) {
			echo "$slow_revision\n";
		}
	}
}
