<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos\Repo;

class Git extends \Compare_Files_In_Repos\Repo {
	private $executable = 'git';

	private function exec( $command, &$status = null ) {
		$command = join( ' ', array_filter( [ $this->executable, $this->option_string(), $command ] ) );

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

	public function set_executable( string $executable = 'git' ) : string {
		$old = $this->executable;
		$this->executable = $executable;
		return $old;
	}

	public function is_slow() : bool {
		return false;
	}

	public function revision_of_file( string $file_path ) : string {
		return trim( $this->exec( sprintf(
			'rev-list -1 --abbrev-commit HEAD %s',
			escapeshellarg( $file_path )
		) ) );
	}

	public function meta_data_of_revision( string $revision ) : array {
		list( $author, $date, $message ) = explode( "\x00", $this->exec( sprintf(
			'log -n 1 --pretty=format:"%%an <%%ae>%%x00%%ai%%x00%%B" %s',
			escapeshellarg( $revision )
		) ) );

		$date = self::normalize_datetime( $date );
		$message = trim( $message );

		return compact( 'author', 'date', 'message' );
	}

	protected function contents_of_file_at_revision( string $file_path, string $revision = null ) : string {
		if ( ! $revision ) {
			$contents = file_get_contents( $this->root_path . '/' . $file_path );
			if ( false === $contents ) {
				throw new \Exception( sprintf( 'File "%s" does not exist', $file_path ) );
			}

			return $contents;
		}

		$contents = $this->exec( sprintf(
			'show %s:%s',
			escapeshellarg( $revision ),
			escapeshellarg( $file_path )
		), $status );

		if ( 0 !== $status ) {
			throw new \Exception( sprintf( 'File "%s" does not exist at revision %s', $file_path, $revision ) );
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
			'check-ignore --quiet -- %s',
			escapeshellarg( $file_path )
		), $status );

		return 0 === $status;
	}

	public function revisions_of_file( string $file_path ) : \Traversable {
		$limit = 100;

		$revision = 'HEAD';

		do {
			$log = $this->exec( sprintf(
				'log --name-only --follow -n %d --pretty=format:"%%h%%x00%%p%%x00%%ai" %s -- %s',
				$limit,
				escapeshellarg( $revision ),
				escapeshellarg( $file_path )
			), $status );

			if ( 0 !== $status ) {
				break;
			}

			$entries = explode( "\n\n", $log );
			foreach ( $entries as $entry ) {
				list( $revision, $parent, $remainder ) = explode( "\x00", trim( $entry ) );
				list( $date, $file_path ) = explode( "\n", $remainder );
				$date = self::normalize_datetime( $date );
				yield [ $revision, $date, $file_path ];
			}

			$revision = $parent;
		} while ( $revision && count( $entries ) === $limit );
	}
}
