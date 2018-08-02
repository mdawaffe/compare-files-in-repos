<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

abstract class Transform {
	abstract public function transform( string $contents, string $path ) : string;
}
