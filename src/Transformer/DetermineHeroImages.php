<?php
/**
 * Class DetermineHeroImages.
 *
 * @package AmpProject\AmpWP
 */

namespace AmpProject\AmpWP\Transformer;

use AmpProject\Attribute;
use AmpProject\Dom\Document;
use AmpProject\Optimizer\ErrorCollection;
use AmpProject\Optimizer\ImageDimensions;
use AmpProject\Optimizer\Transformer;
use AmpProject\Optimizer\Transformer\PreloadHeroImage;
use DOMElement;

/**
 * Determine the images to flag as data-hero so the Optimizer can preload them.
 *
 * This transformer checks for the following images in the given order:
 * 1. Header images (including Custom Logo and Custom Header)
 * 2. Featured image of the page
 * 3. Image block in initial position of first entry content
 * 4. Cover block image in initial position of first entry content
 *
 * It then applies the data-hero attribute to the first two of these.
 *
 * @package AmpProject\AmpWP
 * @since   2.1
 * @internal
 */
final class DetermineHeroImages implements Transformer {

	/**
	 * XPath query to find preceding which are not lazy-loaded.
	 *
	 * @var string
	 */
	const PRECEDING_NON_LAZY_IMAGE_XPATH_QUERY = "preceding::amp-img[ not( @data-hero ) ][ not( noscript/img/@loading ) or noscript/img/@loading != 'lazy' ]";

	/**
	 * XPath query to find the featured image.
	 *
	 * @var string
	 */
	const FEATURED_IMAGE_XPATH_QUERY = ".//amp-img[ contains( concat( ' ', normalize-space( @class ), ' ' ), ' wp-post-image ' ) ][ not( @data-hero ) ]";

	/**
	 * XPath query to find the first entry-content.
	 *
	 * @var string
	 */
	const FIRST_ENTRY_CONTENT_XPATH_QUERY = ".//*[ contains( concat( ' ', normalize-space( @class ), ' ' ), ' entry-content ' ) ]";

	/**
	 * XPath query to find background image of a Cover Block at the beginning of post content (including nested inside of another block).
	 *
	 * @var string
	 */
	const INITIAL_COVER_BLOCK_XPATH_QUERY = "./*[1]/descendant-or-self::div[ contains( concat( ' ', normalize-space( @class ), ' ' ), ' wp-block-cover ' ) ]/amp-img[ contains( concat( ' ', normalize-space( @class ), ' ' ), ' wp-block-cover__image-background ' ) ][ not( @data-hero ) ]";

	/**
	 * XPath query to find Image Block at the beginning of post content (including nested inside of another block).
	 *
	 * @var string
	 */
	const INITIAL_IMAGE_BLOCK_XPATH_QUERY = "./*[1]/descendant-or-self::figure[ contains( concat( ' ', normalize-space( @class ), ' ' ), ' wp-block-image ' ) ]/amp-img[ not( @data-hero ) ]";

	/**
	 * Apply transformations to the provided DOM document.
	 *
	 * @param Document        $document DOM document to apply the
	 *                                  transformations to.
	 * @param ErrorCollection $errors   Collection of errors that are collected
	 *                                  during transformation.
	 * @return void
	 */
	public function transform( Document $document, ErrorCollection $errors ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$hero_image_elements = [];

		// @todo Beyond initial image block and cover block, what about an initial embed block?
		foreach ( [ 'header_images', 'featured_image', 'initial_image_block', 'initial_cover_block' ] as $hero_image_source ) {
			if ( count( $hero_image_elements ) < PreloadHeroImage::DATA_HERO_MAX ) {
				$candidate = null;

				switch ( $hero_image_source ) {
					case 'header_images':
						$candidate = $this->get_header_images( $document );
						break;
					case 'featured_image':
						$candidate = $this->get_featured_image( $document );
						break;
					case 'initial_image_block':
						$candidate = $this->get_initial_content_image_block( $document );
						break;
					case 'initial_cover_block':
						$candidate = $this->get_initial_content_cover_block( $document );
						break;
				}

				if ( $candidate instanceof DOMElement ) {
					$hero_image_elements[ spl_object_hash( $candidate ) ] = $candidate;
				} elseif ( is_array( $candidate ) ) {
					foreach ( $candidate as $hero_image_element ) {
						$hero_image_elements[ spl_object_hash( $hero_image_element ) ] = $hero_image_element;
					}
				}
			}
		}

		$this->add_data_hero_candidate_attribute(
			array_slice( array_values( $hero_image_elements ), 0, PreloadHeroImage::DATA_HERO_MAX )
		);
	}

	/**
	 * Retrieve the images in the header.
	 *
	 * This returns all non-tiny images which occur before the main content, or else a tiny image that has `logo` in the
	 * class name.
	 *
	 * @param Document $document Document to retrieve the header images from.
	 * @return DOMElement[] Header images.
	 */
	private function get_header_images( Document $document ) {
		// Note that 3,508 out of 3,923 themes on WP.org  (89%) use the <main> element.
		$after_header_element = $document->getElementsByTagName( 'main' )->item( 0 );

		// If a theme happens to not use the <main> element, then fall back to using the first entry-content.
		if ( ! $after_header_element instanceof DOMElement ) {
			$after_header_element = $this->get_first_entry_content( $document );
		}

		if ( ! $after_header_element instanceof DOMElement ) {
			return [];
		}

		$query = $document->xpath->query(
			self::PRECEDING_NON_LAZY_IMAGE_XPATH_QUERY,
			$after_header_element
		);

		return array_filter(
			iterator_to_array( $query ),
			static function ( DOMElement $element ) {
				// A custom logo may in fact be tiny and yet since it is in the header it should be prerendered.
				// Note that a theme may not be using `the_custom_logo()` template tag and that is why the `custom-logo`
				// class is not being checked for specifically.
				if (
					$element->hasAttribute( Attribute::CLASS_ )
					&&
					false !== strpos( $element->getAttribute( Attribute::CLASS_ ), 'logo' )
				) {
					return true;
				}

				return ! ( new ImageDimensions( $element ) )->isTiny();
			}
		);
	}

	/**
	 * Retrieve the element that represents the featured image.
	 *
	 * @param Document $document Document to retrieve the featured image from.
	 * @return DOMElement|null Element that represents the featured image, or
	 *                         null if not found.
	 */
	private function get_featured_image( Document $document ) {
		$elements = $document->xpath->query(
			self::FEATURED_IMAGE_XPATH_QUERY,
			$document->body
		);

		$featured_image = $elements->item( 0 );

		return $featured_image instanceof DOMElement ? $featured_image : null;
	}

	/**
	 * Retrieve the first entry content.
	 *
	 * @param Document $document Document to retrieve the first entry content from.
	 * @return DOMElement|null First entry content element.
	 */
	private function get_first_entry_content( Document $document ) {
		$query = $document->xpath->query(
			self::FIRST_ENTRY_CONTENT_XPATH_QUERY,
			$document->body
		);

		$entry_content = $query->item( 0 );
		return $entry_content instanceof DOMElement ? $entry_content : null;
	}

	/**
	 * Retrieve the first cover block that is in the first position in content.
	 *
	 * @param Document $document Document to retrieve the cover block from.
	 * @return DOMElement|null Cover block at the beginning of the first entry content.
	 */
	private function get_initial_content_cover_block( Document $document ) {
		$entry_content = $this->get_first_entry_content( $document );
		if ( ! $entry_content instanceof DOMElement ) {
			return null;
		}

		$query = $document->xpath->query(
			self::INITIAL_COVER_BLOCK_XPATH_QUERY,
			$entry_content
		);

		$cover_block_image = $query->item( 0 );
		return $cover_block_image instanceof DOMElement ? $cover_block_image : null;
	}

	/**
	 * Retrieve the first image block that is in the first position in content.
	 *
	 * @param Document $document Document to retrieve the image block from.
	 * @return DOMElement|null Image block at the beginning of the first entry content.
	 */
	private function get_initial_content_image_block( Document $document ) {
		$entry_content = $this->get_first_entry_content( $document );
		if ( ! $entry_content instanceof DOMElement ) {
			return null;
		}

		$query = $document->xpath->query(
			self::INITIAL_IMAGE_BLOCK_XPATH_QUERY,
			$entry_content
		);

		$image = $query->item( 0 );
		return $image instanceof DOMElement ? $image : null;
	}

	/**
	 * Add the data-hero attribute to viable hero images.
	 *
	 * @param DOMElement[] $hero_image_elements Elements that are viable hero
	 *                                          images.
	 */
	private function add_data_hero_candidate_attribute( $hero_image_elements ) {
		foreach ( $hero_image_elements as $hero_image_element ) {
			$hero_image_element->setAttribute( Attribute::DATA_HERO_CANDIDATE, null );
		}
	}
}
