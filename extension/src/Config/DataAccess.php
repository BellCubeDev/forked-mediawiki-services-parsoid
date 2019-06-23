<?php

namespace MWParsoid\Config;

use ContentHandler;
use File;
use Hooks;
use LinkBatch;
use Linker;
use MediaTransformError;
use MediaWiki\Revision\RevisionStore;
use PageProps;
use Parser;
use ParserOptions;
use Parsoid\Config\DataAccess as IDataAccess;
use Parsoid\Config\PageConfig as IPageConfig;
// we can get rid of this once we can assume PHP 7.4+ with covariant return type support
use Parsoid\Config\PageContent as IPageContent;
use RepoGroup;
use Title;

class DataAccess implements IDataAccess {

	/** @var RevisionStore */
	private $revStore;

	/** @var Parser */
	private $parser;

	/** @var ParserOptions */
	private $parserOptions;

	/**
	 * @param RevisionStore $revStore
	 * @param Parser $parser
	 * @param ParserOptions $parserOptions
	 */
	public function __construct(
		RevisionStore $revStore, Parser $parser, ParserOptions $parserOptions
	) {
		$this->revStore = $revStore;
		$this->parser = $parser;
		$this->parserOptions = $parserOptions;
	}

	/**
	 * @param File $file
	 * @param array $hp
	 * @return array
	 */
	private function makeTransformOptions( $file, array $hp ): array {
		// Validate the input parameters like Parser::makeImage()
		$handler = $file->getHandler();
		if ( !$handler ) {
			return []; // will get iconThumb()
		}
		foreach ( $hp as $name => $value ) {
			if ( !$handler->validateParam( $name, $value ) ) {
				unset( $hp[$name] );
			}
		}

		// This part is similar to Linker::makeImageLink(). If there is no width,
		// set one based on the source file size.
		$page = isset( $hp['page'] ) ? $hp['page'] : 1;
		if ( !isset( $hp['width'] ) ) {
			if ( isset( $hp['height'] ) && $file->isVectorized() ) {
				// If it's a vector image, and user only specifies height
				// we don't want it to be limited by its "normal" width.
				global $wgSVGMaxSize;
				$hp['width'] = $wgSVGMaxSize;
			} else {
				$hp['width'] = $file->getWidth( $page );
			}

			// We don't need to fill in a default thumbnail width here, since
			// that is done by Parsoid. Parsoid always sets the width parameter
			// for thumbnails.
		}

		return $hp;
	}

	/** @inheritDoc */
	public function getPageInfo( IPageConfig $pageConfig, array $titles ): array {
		$titleObjs = [];
		foreach ( $titles as $name ) {
			$titleObjs[$name] = Title::newFromText( $name );
		}
		$linkBatch = new LinkBatch( $titleObjs );
		$linkBatch->execute();

		// This depends on the Disambiguator extension :(
		// @todo Either merge that extension into core, or we'll need to make
		// a "ParsoidGetRedlinkData" hook that Disambiguator can implement.
		$pageProps = PageProps::getInstance();
		$properties = $pageProps->getProperties( $titleObjs, [ 'disambiguation' ] );

		$ret = [];
		foreach ( $titleObjs as $name => $obj ) {
			/** @var Title $obj */
			$ret[$name] = [
				'pageId' => $obj->getArticleID(),
				'revId' => $obj->getLatestRevID(),
				'missing' => !$obj->exists(),
				'known' => $obj->isKnown(),
				'redirect' => $obj->isRedirect(),
				'disambiguation' => isset( $properties[$obj->getArticleID()] ),
			];
		}
		return $ret;
	}

	/** @inheritDoc */
	public function getFileInfo( IPageConfig $pageConfig, array $files ): array {
		$page = Title::newFromText( $pageConfig->getTitle() );
		$fileObjs = RepoGroup::singleton()->findFiles( array_keys( $files ) );
		$ret = [];
		foreach ( $files as $filename => $dims ) {
			/** @var File $file */
			$file = $fileObjs[$filename] ?? null;
			if ( !$file ) {
				$ret[$filename] = null;
				continue;
			}

			$result = [
				'width' => $file->getWidth(),
				'height' => $file->getHeight(),
				'size' => $file->getSize(),
				'mediatype' => $file->getMediaType(),
				'mime' => $file->getMimeType(),
				'url' => wfExpandUrl( $file->getFullUrl(), PROTO_CURRENT ),
				'mustRender' => $file->mustRender(),
				'badFile' => (bool)wfIsBadImage( $filename, $page ?: false ),
			];

			$length = $file->getLength();
			if ( $length ) {
				$result['duration'] = (float)$length;
			}
			$txopts = $this->makeTransformOptions( $file, $dims );
			$mto = $file->transform( $txopts );
			if ( $mto ) {
				if ( $mto->isError() && $mto instanceof MediaTransformError ) {
					$result['thumberror'] = $mto->toText();
				} else {
					if ( $txopts ) {
						// Do srcset scaling
						Linker::processResponsiveImages( $file, $mto, $txopts );
						if ( count( $mto->responsiveUrls ) ) {
							$result['responsiveUrls'] = [];
							foreach ( $mto->responsiveUrls as $density => $url ) {
								$result['responsiveUrls'][$density] = wfExpandUrl(
									$url, PROTO_CURRENT );
							}
						}
					}

					// Proposed MediaTransformOutput serialization method for T51896 etc.
					if ( is_callable( [ $mto, 'getAPIData' ] ) ) {
						$result['thumbdata'] = $mto->getAPIData();
					}

					$result['thumburl'] = wfExpandUrl( $mto->getUrl(), PROTO_CURRENT );
					$result['thumbwidth'] = $mto->getWidth();
					$result['thumbheight'] = $mto->getHeight();
				}
			} else {
				$result['thumberror'] = "Presumably, invalid parameters, despite validation.";
			}

			$ret[$filename] = $result;
		}

		return $ret;
	}

	/** @inheritDoc */
	public function doPst( IPageConfig $pageConfig, string $wikitext ): string {
		$titleObj = Title::newFromText( $pageConfig->getTitle() );
		return ContentHandler::makeContent( $wikitext, $titleObj, CONTENT_MODEL_WIKITEXT )
			->preSaveTransform( $titleObj, $this->parserOptions->getUser(), $this->parserOptions )
			->serialize();
	}

	/** @inheritDoc */
	public function parseWikitext(
		IPageConfig $pageConfig, string $wikitext, ?int $revid = null
	): array {
		$titleObj = Title::newFromText( $pageConfig->getTitle() );
		$out = ContentHandler::makeContent( $wikitext, $titleObj, CONTENT_MODEL_WIKITEXT )
			->getParserOutput( $titleObj, $revid, $this->parserOptions );

		$categories = [];
		foreach ( $out->getCategories() as $cat => $sortkey ) {
			$categories[] = [ 'name' => $cat, 'sortkey' => $sortkey ];
		}

		return [
			'html' => $out->getText( [ 'unwrap' => true ] ),
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulescripts' => [], // $out->getModuleScripts() is deprecated and always returns []
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
			'categories' => $out->getCategories(),
			// @todo ParsoidBatchAPI also returns page properties, but they don't seem to be used in Parsoid?
		];
	}

	/** @inheritDoc */
	public function preprocessWikitext(
		IPageConfig $pageConfig, string $wikitext, ?int $revid = null
	): array {
		$titleObj = Title::newFromText( $pageConfig->getTitle() );
		$wikitext = $this->parser->preprocess( $wikitext, $titleObj, $this->parserOptions, $revid );
		$out = $this->parser->getOutput();
		return [
			'wikitext' => $wikitext,
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulescripts' => [], // $out->getModuleScripts() is deprecated and always returns []
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
			'categories' => $out->getCategories(),
			// @todo ParsoidBatchAPI also returns page properties, but they don't seem to be used in Parsoid?
		];
	}

	/** @inheritDoc */
	public function fetchPageContent(
		IPageConfig $pageConfig, string $title, int $oldid = 0
	): ?IPageContent {
		$titleObj = Title::newFromText( $title );

		if ( $oldid ) {
			$rev = $this->revStore->getRevisionByTitle( $titleObj, $oldid );
		} else {
			$rev = call_user_func(
				$this->parserOptions->getCurrentRevisionCallback(),
				$titleObj,
				$this->parser
			);
		}
		if ( $rev instanceof \Revision ) {
			$rev = $rev->getRevisionRecord();
		}

		return $rev ? new PageContent( $rev ) : null;
	}

	/** @inheritDoc */
	public function fetchTemplateData( IPageConfig $pageConfig, string $title ): ?array {
		$ret = null;
		// @todo: Document this hook in MediaWiki
		Hooks::runWithoutAbort( 'ParsoidFetchTemplateData', [ $title, &$ret ] );
		return $ret;
	}

}
