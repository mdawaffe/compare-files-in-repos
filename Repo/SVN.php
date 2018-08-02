<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos\Repo;

class SVN extends \Compare_Files_In_Repos\Repo {
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
		static $is_slow;
		if ( isset( $is_slow ) ) {
			return $is_slow;
		}

		$xml = $this->exec( 'svn info --xml' );
		
		$entity_loader = libxml_disable_entity_loader( true );

		$info = simplexml_load_string( $xml );
		$is_slow = 0 !== strpos( (string) $info->entry->url, 'file://' );

		libxml_disable_entity_loader( $entity_loader );

		return $is_slow;
	}

	public function revision_of_file( string $file_path ) : string {
		$rev = trim( $this->exec( sprintf(
			'svn info %s | grep "Last Changed Rev:"',
			escapeshellarg( $file_path )
		) ) );

		return explode( ': ', $rev )[1];
	}

	public function meta_data_of_revision( string $revision ) : array {
		$xml = $this->exec( sprintf(
			'svn log --xml -r %s',
			escapeshellarg( $revision )
		) );

		$entity_loader = libxml_disable_entity_loader( true );

		$log = simplexml_load_string( $xml );
		$logentry = $log->logentry;

		$data = [
			'author' => trim( (string) $logentry->author ),
			'date' => trim( (string) $logentry->date ),
			'message' => trim( (string) $logentry->msg ),
		];

		libxml_disable_entity_loader( $entity_loader );

		return $data;
	}

	protected function contents_of_file_at_revision( string $file_path, string $revision = null ) : string {
		if ( ! $revision ) {
			$contents = file_get_contents( $this->root_path . '/' . $file_path );
			if ( false === $contents ) {
				throw new Exception( sprintf( 'File "%s" does not exist', $file_path ) );
			}

			return $contents;
		}

		$contents = $this->exec( sprintf(
			'svn cat -r %s %s',
			escapeshellarg( $revision ),
			escapeshellarg( $file_path )
		), $status );

		if ( 0 !== $status ) {
			throw new Exception( sprintf( 'File "%s" does not exist at revision %s', $file_path, $revision ) );
		}

		return $contents;
	}

	public function is_ignored( string $file_path ) : bool {
		$path_pieces = explode( '/', $file_path );
		if ( in_array( '.svn', $path_pieces, true ) ) {
			$this->logger->debug( sprintf( 'Ignoring .svn file: %s', $file_path ) );
			return true;
		}

		// Doesn't work for files that do not exist
		$svn_status_flag = substr( $this->exec( sprintf(
			'svn status %s',
			escapeshellarg( $this->root_path . '/' . $file_path )
		) ), 0, 1 );

		return 'I' === $svn_status_flag;
	}

	public function revisions_of_file( string $file_path ) : iterable {
		$limit = 100;

		$revision = 'BASE';

		do {
			$xml = $this->exec( sprintf(
				'svn log --quiet --xml --limit %d %s@%s',
				$limit,
				escapeshellarg( $file_path ),
				escapeshellarg( (string) $revision )
			), $status );

			if ( 0 !== $status ) {
				break;
			}

			$entity_loader = libxml_disable_entity_loader( true );

			$log = simplexml_load_string( $xml );
			$revisions = [];
			foreach ( $log->logentry as $logentry ) {
				$revisions[] = (int) $logentry['revision'];
			}

			libxml_disable_entity_loader( $entity_loader );

			foreach ( $revisions as $revision ) {
				yield (string) $revision;
			}

			$revision = $revision - 1;
		} while ( count( $revisions ) === $limit && 0 < $revision );
	}
}
