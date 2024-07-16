<?php

declare( strict_types = 1 );

namespace Northrook\Storage;

use InvalidArgumentException;
use Northrook\Core\Timestamp;
use Northrook\Filesystem\File;
use Northrook\Logger\Log;
use Northrook\PersistentStorageManager;
use Symfony\Component\VarExporter\Exception\ExceptionInterface;
use Symfony\Component\VarExporter\VarExporter;
use function Northrook\Core\hashKey;
use function Northrook\Core\normalizeKey;
use function Northrook\Core\normalizePath;

abstract class PersistentEntity implements PersistentEntityInterface
{
    public const FILE_EXTENSION = '.resource.php';

    private readonly string $storageDirectory;
    private readonly string $filename;

    protected readonly string $hash;
    protected readonly mixed  $type;
    protected mixed           $data;

    public readonly string $name;

    public function __construct(
        ?string        $name,
        mixed          $data = null,
        protected bool $readonly = false,
        protected bool $autosave = false,
        ?string        $directory = null,
    ) {
        $this->resourceName( $name );
        $this->resourceData( $data );
        $this->storageDirectory( $directory );
    }

    /**
     * Update the {@see PersistentEntity::$data} on-demand.
     */
    public function __destruct() {
        if ( $this->readonly || !$this->autosave ) {
            return;
        }
        dump( 'Autosave' );
        if ( $this->hash !== $this->dataHash() ) {
            $this->save();
        }
    }

    abstract public static function hydrate( array $resource ) : self;

    final public function save() : void {

        if ( $this->readonly ) {
            Log::notice( "Could not save $this->name, as it is readonly." );
            return;
        }

        $hash      = $this->dataHash();
        $generated = new Timestamp();
        $generator = $this::class;
        $dataStore = $this->exportData( $generated );

        $content = <<<PHP
            <?php // $generated->unixTimestamp

            /*---------------------------------------------------------------------
            
               Name      : $this->name
               Generated : $generated->datetime
               Hash      : $hash

               This file is generated by $generator.

               Do not edit it manually.

               See https://github.com/northrook/cache for more information.

            ---------------------------------------------------------------------*/

            return $dataStore;
            PHP;

        File::save( $this->getFilePath(), $content );
    }

    final protected function exportData( Timestamp $generated ) : string {
        try {
            return VarExporter::export(
                [
                    'name'      => $this->name,
                    'path'      => $this->getFilePath(),
                    'generator' => $this::class,
                    'generated' => $generated->datetime,
                    'timestamp' => $generated->unixTimestamp,
                    'type'      => gettype( $this->data ),
                    'hash'      => $this->dataHash(),
                    'data'      => $this->data,
                ],
            );
        }
        catch ( ExceptionInterface $exception ) {
            throw new InvalidArgumentException(
                message  : "Unable to export the $this->name dataStore.",
                code     : 500,
                previous : $exception,
            );
        }
    }

    final public function getFilePath() : string {
        $filename = normalizeKey( $this->name );
        return $this->filename ??= normalizePath(
            "$this->storageDirectory/$filename" . PersistentEntity::FILE_EXTENSION,
        );
    }

    final public function exists() : bool {
        return File::exists( $this->getFilePath() );
    }

    // Generate a hash pre-export for validation
    final protected function dataHash( mixed $data = null ) : string {
        return hashKey( $data ?? $this->data );
    }


    private function resourceName( ?string $string ) : void {
        $this->name = class_exists( $string ) ? $string : normalizeKey( $string );
    }

    private function resourceData( mixed $data ) : void {
        $this->type = gettype( $data );
        $this->data = $data;
        $this->hash = hashKey( $data );
    }

    private function storageDirectory( ?string $directory ) : void {
        $this->storageDirectory = normalizePath(
            $directory ?? PersistentStorageManager::getStorageDirectory(),
        );
    }
}