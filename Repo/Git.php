<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos\Repo;

class Git extends \Compare_Files_In_Repos\Repo {
	private function exec( $command, &$status = null ) {
		$exec = proc_open( $command, [
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		], $pipes, $this->root_path );

		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$status = proc_close( $exec );

		$this->logger->debug( $command, compact( 'status' ) );

		if ( trim( $error ) ) {
			$this->logger->warning( $error, compact( 'command', 'status' ) );
		}

		return $output;
	}

	public function is_slow() : bool {
		return false;
	}

	public function revision_of_file( string $file_path ) : string {
		return trim( $this->exec( sprintf(
			'git rev-list -1 --abbrev-commit HEAD %s',
			escapeshellarg( $file_path )
		) ) );
	}

	public function meta_data_of_revision( string $revision ) : array {
		[ $author, $date, $message ] = explode( "\x00", $this->exec( sprintf(
			'git log -n 1 --pretty=format:"%%an <%%ae>%%x00%%aI%%x00%%B" %s',
			escapeshellarg( $revision )
		) ) );

		$message = trim( $message );

		return compact( 'author', 'date', 'message' );
	}

	protected function contents_of_file_at_revision( string $file_path, string $revision = null ) : string {
		if ( ! $revision ) {
			$contents = file_get_contents( $this->root_path . '/' . $file_path );
			if ( false === $contents ) {
				throw New Exception( sprintf( 'File "%s" does not exist', $file_path ) );
			}

			return $contents;
		}

		$contents = $this->exec( sprintf(
			'git show %s:%s',
			escapeshellarg( $revision ),
			escapeshellarg( $file_path )
		), $status );

		if ( 0 !== $status ) {
			throw New Exception( sprintf( 'File "%s" does not exist at revision %s', $file_path, $revision ) );
		}

		return $contents;
	}

	public function is_ignored( string $file_path ) : bool {
		$path_pieces = explode( '/', $file_path );
		if ( in_array( '.git', $path_pieces, true ) ) {
			$this->logger->debug( sprintf( 'Ignoring .git file: %s', $file_path ) );
			return true;
		}

		$this->exec( sprintf(
			'git check-ignore --quiet -- %s',
			escapeshellarg( $file_path )
		), $status );

		return 0 === $status;
	}

	public function revisions_of_file( string $file_path ) : iterable {
		$limit = 100;

		$revision = 'HEAD';

		do {
			$log = $this->exec( sprintf(
				'git log --name-only --follow -n %d --pretty=format:"%%h:%%p" %s -- %s',
				$limit,
				escapeshellarg( $revision ),
				escapeshellarg( $file_path )
			), $status );

			if ( 0 !== $status ) {
				break;
			}

			$entries = explode( "\n\n", $log );
			foreach ( $entries as $entry ) {
				[ $revision, $remainder ] = explode( ':', trim( $entry ) );
				[ $parent, $file_path ] = explode( "\n", trim( $remainder ) );
				yield [ $revision, $file_path ];
			}

			$revision = $parent;
		} while ( $revision && count( $entries ) === $limit );
	}
}
