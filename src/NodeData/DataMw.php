<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\NodeData;

use stdClass;
use Wikimedia\JsonCodec\Hint;
use Wikimedia\JsonCodec\JsonCodecable;
use Wikimedia\JsonCodec\JsonCodecableTrait;
use Wikimedia\Parsoid\Tokens\SourceRange;

/**
 * Editing data for a DOM node.  Managed by DOMDataUtils::get/setDataMw().
 *
 * To reduce memory usage, most of the properties need to be dynamic, but
 * we use the property declarations below to allow type checking.
 *
 * @property list<string|TemplateInfo> $parts
 * @property string $name
 * @property string $extPrefix
 * @property string $extSuffix
 * @property list<DataMwAttrib> $attribs Extended attributes of an HTML tag
 * @property string $src
 * @property string $caption
 * @property string $thumb
 * @property bool $autoGenerated
 * @property list<DataMwError> $errors
 * @property stdClass $body
 * @property mixed $html
 * @property float $scale
 * @property string $starttime
 * @property string $endtime
 * @property string $thumbtime
 * @property string $page
 * == Annotations ==
 * @property string $rangeId
 * @property SourceRange $wtOffsets
 * @property bool $extendedRange
 * @property stdClass $attrs Attributes for an extension tag or annotation (T367616 should be renamed)
 */
#[\AllowDynamicProperties]
class DataMw implements JsonCodecable {
	use JsonCodecableTrait;

	public function __construct( array $initialVals = [] ) {
		foreach ( $initialVals as $k => $v ) {
			// @phan-suppress-next-line PhanNoopSwitchCases
			switch ( $k ) {
				// Add cases here for components which should be instantiated
				// as proper classes.
				default:
					$this->$k = $v;
					break;
			}
		}
	}

	/** Returns true iff there are no dynamic properties of this object. */
	public function isEmpty(): bool {
		return ( (array)$this ) === [];
	}

	public function __clone() {
		// Deep clone non-primitive properties
		if ( isset( $this->parts ) ) {
			foreach ( $this->parts as &$part ) {
				if ( !is_string( $part ) ) {
					$part = clone $part;
				}
			}
		}
		// Properties which are lists of cloneable objects
		foreach ( [ 'attribs', 'errors' ] as $prop ) {
			if ( isset( $this->$prop ) ) {
				foreach ( $this->$prop as &$item ) {
					$item = clone $item;
				}
			}
		}
		// Properties which are cloneable objects
		foreach ( [ 'wtOffsets' ] as $prop ) {
			if ( isset( $this->$prop ) ) {
				$this->$prop = clone $this->$prop;
			}
		}
		// Generic stdClass, use PHP serialization as a kludge
		foreach ( [ 'body', 'attrs' ] as $prop ) {
			if ( isset( $this->$prop ) ) {
				$this->$prop = unserialize( serialize( $this->$prop ) );
			}
		}
	}

	/** @inheritDoc */
	public static function jsonClassHintFor( string $keyname ) {
		static $hints = null;
		if ( $hints === null ) {
			$hints = [
				'attribs' => Hint::build( DataMwAttrib::class, Hint::USE_SQUARE, Hint::LIST ),
				// T367616: 'attrs' should be renamed to 'extAttrs'
				'attrs' => Hint::build( stdClass::class, Hint::ALLOW_OBJECT ),
				'body' => Hint::build( stdClass::class, Hint::ALLOW_OBJECT ),
				'wtOffsets' => Hint::build( SourceRange::class, Hint::USE_SQUARE ),
				'parts' => Hint::build( TemplateInfo::class, Hint::STDCLASS, Hint::LIST ),
				'errors' => Hint::build( DataMwError::class, Hint::LIST ),
			];
		}
		return $hints[$keyname] ?? null;
	}

	/** @inheritDoc */
	public function toJsonArray(): array {
		$result = (array)$this;
		// T367141: Third party clients (eg Cite) create arrays instead of
		// error objects.  We should convert them to proper DataMwError
		// objects once those exist.
		if ( isset( $result['errors'] ) ) {
			$result['errors'] = array_map(
				static fn ( $e ) => is_array( $e ) ? DataMwError::newFromJsonArray( $e ) :
					( $e instanceof DataMwError ? $e : DataMwError::newFromJsonArray( (array)$e ) ),
				$result['errors']
			);
		}
		// Legacy encoding of parts.
		if ( isset( $result['parts'] ) ) {
			$result['parts'] = array_map( static function ( $p ) {
				if ( $p instanceof TemplateInfo ) {
					$type = $p->type ?? 'template';
					if ( $type === 'parserfunction' ) {
						$type = 'template';
					} elseif ( $type === 'v3parserfunction' ) {
						$type = 'parserfunction';
					}
					$pp = (object)[];
					$pp->$type = $p;
					return $pp;
				}
				return $p;
			}, $result['parts'] );
		}
		return $result;
	}

	/** @inheritDoc */
	public static function newFromJsonArray( array $json ): DataMw {
		// Decode legacy encoding of parts.
		if ( isset( $json['parts'] ) ) {
			$json['parts'] = array_map( static function ( $p ) {
				if ( is_object( $p ) ) {
					$ptype = $type = 'template';
					if ( isset( $p->templatearg ) ) {
						$ptype = $type = 'templatearg';
					} elseif ( isset( $p->parserfunction ) ) {
						$type = 'parserfunction';
						$ptype = 'v3parserfunction';
					}
					$p = $p->$type;
					if ( isset( $p->func ) ) {
						$ptype = 'parserfunction';
					}
					$p->type = $ptype;
				}
				return $p;
			}, $json['parts'] );
		}
		return new DataMw( $json );
	}
}
