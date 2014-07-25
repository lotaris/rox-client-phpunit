<?php

namespace Lotaris\RoxClientPHPUnit;

use Lotaris\RoxClientPHPUnit\RoxClientException;

/**
 * Annotation to make a test "Roxable", i.e., to send its results to Rox center.
 * http://php-and-symfony.matthiasnoback.nl/2011/12/symfony2-doctrine-common-creating-powerful-annotations/
 * 
 * @Annotation
 * @Target("METHOD")
 *
 * @author Francois Vessaz <francois.vessaz@lotaris.com>
 */
class RoxableTest {

	const NO_FLAGS = 0;
	const INACTIVE_TEST_FLAG = 1;

	private $roxAnnotations;

	public function __construct($options) {
		$this->roxAnnotations = $options;
	}

	public function getKey() {
		if (!isset($this->roxAnnotations['key'])) {
			throw new RoxClientException('A RoxableTest annotation was found, but the ROX test key is not set.');
		}
		$key = $this->roxAnnotations['key'];
		if ($key === null || !is_string($key) || empty($key)) {
			throw new RoxClientException('A RoxableTest annotation was found, but the ROX test key is not valid.');
		}
		return $key;
	}

	public function getName() {
		if (isset($this->roxAnnotations['name'])) {
			$name = $this->roxAnnotations['name'];
			if ($name === null || !is_string($name) || empty($name)) {
				return null;
			}
			return $name;
		}
		return null;
	}

	public function getCategory() {
		if (isset($this->roxAnnotations['category'])) {
			$category = $this->roxAnnotations['category'];
			if ($category === null || !is_string($category) || empty($category)) {
				return null;
			}
			return $category;
		}
		return null;
	}

	public function getTags() {
		if (isset($this->roxAnnotations['tags'])){
			$tags = explode(',', $this->roxAnnotations['tags']);
			foreach ($tags as $i => $tag) {
				if (empty($tag)){
					unset($tags[$i]);
				}
			}
			if (empty($tags)){
				return null;
			} else {
				array_values($tags);
			  return $tags;
			}
		}
		return null;
	}

	public function getTickets() {
		if (isset($this->roxAnnotations['tickets'])){
			$tickets = explode(',', $this->roxAnnotations['tickets']);
			foreach ($tickets as $i => $ticket) {
				if (empty($ticket)){
					unset($tickets[$i]);
				}
			}
			if (empty($tickets)){
				return null;
			} else {
				array_values($tickets);
			  return $tickets;
			}
		}
		return null;
	}

	// We do not return an array of flags, because there is only one flag (INVALID).
	public function getFlags() {
		if (isset($this->roxAnnotations['tickets']) && $this->roxAnnotations['tickets'] === 'INVALID') {
			return self::INACTIVE_TEST_FLAG;
		} else {
			return self::NO_FLAGS;
		}
	}

}
