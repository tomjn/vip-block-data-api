<?php

namespace WPCOMVIP\ContentApi;

defined( 'ABSPATH' ) || die();

use WP_Block_Type;
use WP_Block_Type_Registry;
use Symfony\Component\DomCrawler\Crawler;

class ContentParser {
	protected $block_registry;
	protected $post_id;
	protected $warnings = [];

	/**
	 * @param WP_Block_Type_Registry|null $block_registry
	 */
	public function __construct( $block_registry = null ) {
		if ( null === $block_registry ) {
			$block_registry = WP_Block_Type_Registry::get_instance();
		}

		$this->block_registry = $block_registry;
	}

	/**
	 * @param string $content
	 * @param string $post_content HTML content of a post.
	 * @param int|null $post_id ID of the post being parsed. Required for blocks containing meta-sourced attributes and some block filters.
	 *
	 * @return array[string]array
	 */
	public function parse( $post_content, $post_id = null ) {
		$this->post_id  = $post_id;
		$this->warnings = [];

		$blocks = parse_blocks( $post_content );
		$blocks = array_values(array_filter($blocks, function( $block ) {
			$is_whitespace_block = ( null === $block['blockName'] && empty( trim( $block['innerHTML'] ) ) );
			return ! $is_whitespace_block;
		}));

		$registered_blocks = $this->block_registry->get_all_registered();

		$sourced_blocks = array_map(function( $block ) use ( $registered_blocks ) {
			return $this->source_block( $block, $registered_blocks );
		}, $blocks);

		$result = [
			'blocks' => $sourced_blocks,
		];

		if ( ! empty( $this->warnings ) ) {
			$result['warnings'] = $this->warnings;
		}

		// Debug output
		if ( $this->is_debug_enabled() ) {
			$result['debug'] = [
				'blocks_parsed' => $blocks,
				'content'       => $post_content,
			];
		}

		return $result;
	}

	/**
	 * @param array[string]array $block
	 * @param WP_Block_Type[] $registered_blocks
	 *
	 * @return array[string]array
	 */
	protected function source_block( $block, $registered_blocks ) {
		$block_name = $block['blockName'];

		if ( ! isset( $registered_blocks[ $block_name ] ) ) {
			$this->add_missing_block_warning( $block_name );
		}

		$block_definition            = $registered_blocks[ $block_name ] ?? null;
		$block_definition_attributes = $block_definition->attributes ?? [];

		$block_attributes = $block['attrs'];

		foreach ( $block_definition_attributes as $block_attribute_name => $block_attribute_definition ) {
			$attribute_source        = $block_attribute_definition['source'] ?? null;
			$attribute_default_value = $block_attribute_definition['default'] ?? null;

			if ( null === $attribute_source ) {
				// Unsourced attributes are stored in the block's delimiter attributes, skip DOM parser

				if ( isset( $block_attributes[ $block_attribute_name ] ) ) {
					// Attribute is already set in the block's delimiter attributes, skip
					continue;
				} elseif ( null !== $attribute_default_value ) {
					// Attribute is unset and has a default value, use default value
					$block_attributes[ $block_attribute_name ] = $attribute_default_value;
					continue;
				} else {
					// Attribute is unset and has no default value, skip
					continue;
				}
			}

			// Specify a manual doctype so that the parser will use the HTML5 parser
			$crawler = new Crawler( sprintf( '<!doctype html><html><body>%s</body></html>', $block['innerHTML'] ) );

			// Enter the <body> tag for block parsing
			$crawler = $crawler->filter( 'body' );

			$attribute_value = $this->source_attribute( $crawler, $block_attribute_definition );

			if ( null !== $attribute_value ) {
				$block_attributes[ $block_attribute_name ] = $attribute_value;
			}
		}

		$sourced_block = [
			'name'       => $block_name,
			'attributes' => $block_attributes,
		];

		if ( isset( $block['innerBlocks'] ) ) {
			$inner_blocks = array_map( function( $block ) use ( $registered_blocks ) {
				return $this->source_block( $block, $registered_blocks );
			}, $block['innerBlocks'] );

			if ( ! empty( $inner_blocks ) ) {
				$sourced_block['innerBlocks'] = $inner_blocks;
			}
		}

		if ( $this->is_debug_enabled() ) {
			$sourced_block['debug'] = [
				'block_definition_attributes' => $block_definition->attributes,
			];
		}

		/**
		 * Filters a block when parsing is complete.
		 *
		 * @param array[string]array $sourced_block An associative array of parsed block data with keys 'name' and 'attribute'.
		 * @param string $block_name The name of the parsed block, e.g. 'core/paragraph'.
		 * @param string $post_id The post ID associated with the parsed block.
		 * @param string $block The result of parse_blocks() for this block. Contains 'blockName', 'attrs', 'innerHTML', and 'innerBlocks' keys.
		 */
		$sourced_block = apply_filters( 'vip_content_api__sourced_block_result', $sourced_block, $block_name, $this->post_id, $block );

		// If attributes are empty, explicitly use an object to avoid encoding an empty array in JSON
		if ( empty( $sourced_block['attributes'] ) ) {
			$sourced_block['attributes'] = (object) [];
		}

		return $sourced_block;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 */
	protected function source_attribute( $crawler, $block_attribute_definition ) {
		$attribute_value         = null;
		$attribute_default_value = $block_attribute_definition['default'] ?? null;
		$attribute_source        = $block_attribute_definition['source'];

		// See block attribute sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#value-source
		if ( 'attribute' === $attribute_source || 'property' === $attribute_source ) {
			// 'property' sources were removed in 2018. Default to attribute value.
			// https://github.com/WordPress/gutenberg/pull/8276

			$attribute_value = $this->source_block_attribute( $crawler, $block_attribute_definition );
		} elseif ( 'html' === $attribute_source ) {
			$attribute_value = $this->source_block_html( $crawler, $block_attribute_definition );
		} elseif ( 'text' === $attribute_source ) {
			$attribute_value = $this->source_block_text( $crawler, $block_attribute_definition );
		} elseif ( 'tag' === $attribute_source ) {
			$attribute_value = $this->source_block_tag( $crawler, $block_attribute_definition );
		} elseif ( 'raw' === $attribute_source ) {
			$attribute_value = $this->source_block_raw( $crawler, $block_attribute_definition );
		} elseif ( 'query' === $attribute_source ) {
			$attribute_value = $this->source_block_query( $crawler, $block_attribute_definition );
		} elseif ( 'children' === $attribute_source ) {
			$attribute_value = $this->source_block_children( $crawler, $block_attribute_definition );
		} elseif ( 'meta' === $attribute_source ) {
			$attribute_value = $this->source_block_meta( $block_attribute_definition );
		}

		if ( null === $attribute_value ) {
			$attribute_value = $attribute_default_value;
		}

		return $attribute_value;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_attribute( $crawler, $block_attribute_definition ) {
		// 'attribute' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#attribute-source

		$attribute_value = null;
		$attribute       = $block_attribute_definition['attribute'];
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$attribute_value = $crawler->attr( $attribute );
		}

		return $attribute_value;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_html( $crawler, $block_attribute_definition ) {
		// 'html' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#html-source

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$multiline_selector = $block_attribute_definition['multiline'] ?? null;

			if ( null === $multiline_selector ) {
				$attribute_value = $crawler->html();
			} else {
				$multiline_parts = $crawler->filter( $multiline_selector )->each(function( $node ) {
					return $node->outerHtml();
				});

				$attribute_value = join( '', $multiline_parts );
			}
		}

		return $attribute_value;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_text( $crawler, $block_attribute_definition ) {
		// 'text' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#text-source

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$attribute_value = $crawler->text();
		}

		return $attribute_value;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_query( $crawler, $block_attribute_definition ) {
		// 'query' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#query-source

		$query_items = $block_attribute_definition['query'];
		$selector    = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		$attribute_values = $crawler->each(function ( $node ) use ( $query_items ) {
			$attribute_value = array_map(function( $query_item ) use ( $node ) {
				return $this->source_attribute( $node, $query_item );
			}, $query_items);

			// Remove unsourced query values
			$attribute_value = array_filter( $attribute_value, function( $value ) {
				return null !== $value;
			});

			return $attribute_value;
		});


		return $attribute_values;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_children( $crawler, $block_attribute_definition ) {
		// 'children' attribute usage was removed from core in 2018, but not officically deprecated until WordPress 6.1:
		// https://github.com/WordPress/gutenberg/pull/44265
		// Parsing code for 'children' can be found in these places:
		// https://github.com/WordPress/gutenberg/blob/dd0504b/packages/blocks/src/api/parser/get-block-attributes.js#L215-L216
		// https://github.com/WordPress/gutenberg/blob/dd0504b/packages/blocks/src/api/children.js#L149

		$attribute_values = [];
		$selector         = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() === 0 ) {
			// If the selector doesn't exist, return a default empty array
			return $attribute_values;
		}

		$children = $crawler->children();

		if ( $children->count() === 0 ) {
			// 'children' attributes can be a single element. In this case, return the element value in an array.
			$attribute_values = [
				$crawler->html(),
			];
		} else {
			// Use DOMDocument childNodes directly to preserve text nodes. $crawler->children() will return only
			// element nodes and omit text content.
			$children_nodes = $crawler->getNode( 0 )->childNodes;

			foreach ( $children_nodes as $node ) {
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- external API calls
				if ( XML_ELEMENT_NODE === $node->nodeType ) {
					$attribute_values[] = $node->ownerDocument->saveHtml( $node );
				} elseif ( XML_TEXT_NODE === $node->nodeType ) {
					$text = trim( $node->nodeValue );

					// Exclude whitespace-only nodes
					if ( ! empty( $text ) ) {
						$attribute_values[] = $text;
					}
				}
				// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		return $attribute_values;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_tag( $crawler, $block_attribute_definition ) {
		// The only current usage of the 'tag' attribute is Gutenberg core is the 'core/table' block:
		// https://github.com/WordPress/gutenberg/blob/796b800/packages/block-library/src/table/block.json#L39
		// Also see tag attribute parsing in Gutenberg:
		// https://github.com/WordPress/gutenberg/blob/6517008/packages/blocks/src/api/parser/get-block-attributes.js#L225

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$attribute_value = strtolower( $crawler->nodeName() );
		}

		return $attribute_value;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_raw( $crawler, $block_attribute_definition ) {
		// The only current usage of the 'raw' attribute in Gutenberg core is the 'core/html' block:
		// https://github.com/WordPress/gutenberg/blob/6517008/packages/block-library/src/html/block.json#L13
		// Also see tag attribute parsing in Gutenberg:
		// https://github.com/WordPress/gutenberg/blob/6517008/packages/blocks/src/api/parser/get-block-attributes.js#L131

		$attribute_value = null;

		if ( $crawler->count() > 0 ) {
			$attribute_value = trim( $crawler->html() );
		}

		return $attribute_value;
	}

	/**
	 * @param Symfony\Component\DomCrawler\Crawler $crawler
	 * @param array $block_attribute_definition
	 *
	 * @return string|null
	 */
	protected function source_block_meta( $block_attribute_definition ) {
		// 'meta' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#meta-source

		$post = get_post( $this->post_id );
		if ( null === $post ) {
			return null;
		}

		$meta_key            = $block_attribute_definition['meta'];
		$is_metadata_present = metadata_exists( 'post', $post->ID, $meta_key );

		if ( ! $is_metadata_present ) {
			return null;
		} else {
			return get_post_meta( $post->ID, $meta_key, true );
		}
	}

	protected function add_missing_block_warning( $block_name ) {
		$warning_message = sprintf( 'Block type "%s" is not server-side registered. Sourced block attributes will not be available.', $block_name );

		if ( ! in_array( $warning_message, $this->warnings ) ) {
			$this->warnings[] = $warning_message;
		}
	}

	protected function is_debug_enabled() {
		return defined( 'VIP_CONTENT_API__PARSE_DEBUG' ) && constant( 'VIP_CONTENT_API__PARSE_DEBUG' ) === true;
	}
}
