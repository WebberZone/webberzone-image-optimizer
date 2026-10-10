<?php
/**
 * Tag processor that reports the byte span of the current tag.
 *
 * @package WebberZone\Image_Optimizer
 */

namespace WebberZone\Image_Optimizer\Frontend;

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Lets a caller wrap a tag in new markup, which the Tag Processor cannot do itself.
 *
 * @since 1.2.1
 */
class Tag_Span_Processor extends \WP_HTML_Tag_Processor {

	/**
	 * Byte offset and length of the tag the processor is paused on.
	 *
	 * @since 1.2.1
	 *
	 * @return array{0: int, 1: int}|null Start and length, or null when not paused on a tag.
	 */
	public function get_tag_span(): ?array {
		if ( ! $this->set_bookmark( 'wzio' ) ) {
			return null;
		}

		$span = $this->bookmarks['wzio'];

		return array( (int) $span->start, (int) $span->length );
	}
}
