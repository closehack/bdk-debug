<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2021 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Psr7lite;

use InvalidArgumentException;
use RuntimeException;

/**
 * INTERNAL USE ONLY
 */
class Stream
{
    /**
     * Resource modes.
     *
     * @var string
     *
     * @see http://php.net/manual/function.fopen.php
     * @see http://php.net/manual/en/function.gzopen.php
     */
    const READABLE_MODES = '/r|a\+|ab\+|w\+|wb\+|x\+|xb\+|c\+|cb\+/';
    const WRITABLE_MODES = '/a|w|r\+|rb\+|rw|x|c/';

    /** @var resource|null A resource reference */
    private $resource;
    private $size;
    private $seekable;
    private $readable;
    private $writable;
    private $uri;
    private $customMetadata;

    /**
     * This constructor accepts an associative array of options.
     *
     * - metadata: (array) Any additional metadata to return when the metadata
     *   of the stream is accessed.
     * - size: (int) If a read stream would otherwise have an indeterminate
     *   size, but the size is known due to foreknowledge, then you can
     *   provide that size, in bytes.
     *
     * @param mixed $resource Resource, file, or string content to wrap.
     * @param array $options  Associative array of options.
     *
     * @throws InvalidArgumentException if the stream is not a stream resource
     */
    public function __construct($resource = null, $options = array())
    {
        $this->setResource($resource);
        if (isset($options['size'])) {
            $this->size = $options['size'];
        }
        $this->customMetadata = isset($options['metadata'])
            ? $options['metadata']
            : array();

        $meta = \stream_get_meta_data($this->resource);
        $this->seekable = $meta['seekable'];
        $this->readable = (bool) \preg_match(self::READABLE_MODES, $meta['mode']);
        $this->writable = (bool) \preg_match(self::WRITABLE_MODES, $meta['mode']);
        $this->uri = $this->getMetadata('uri');
    }

    /**
     * Closes the stream when the destructed
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * @return string
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     */
    public function __toString()
    {
        try {
            $this->seek(0);
            return (string) \stream_get_contents($this->resource);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close()
    {
        if (isset($this->resource)) {
            if (\is_resource($this->resource)) {
                \fclose($this->resource);
            }
            $this->detach();
        }
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        if (!isset($this->resource)) {
            return null;
        }
        $resource = $this->resource;
        unset($this->resource);
        $this->size = null;
        $this->uri = null;
        $this->readable = false;
        $this->seekable = false;
        $this->writable = false;
        return $resource;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (!isset($this->resource)) {
            return null;
        }

        // Clear the stat cache if the stream has a URI
        if ($this->uri) {
            \clearstatcache(true, $this->uri);
        }

        $stats = \fstat($this->resource);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];
            return $this->size;
        }

        return null;
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int Position of the file pointer
     * @throws RuntimeException on error.
     */
    public function tell()
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }

        $result = \ftell($this->resource);
        if ($result === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $result;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     * @throws RuntimeException
     */
    public function eof()
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }
        return \feof($this->resource);
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return $this->seekable;
    }

    /**
     * Seek to a position in the stream.
     *
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *     based on the seek offset. Valid values are identical to the built-in
     *     PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *     offset bytes SEEK_CUR: Set position to current location plus offset
     *     SEEK_END: Set position to end-of-stream plus offset.
     *
     * @link   http://www.php.net/manual/en/function.fseek.php
     * @throws RuntimeException on failure.
     *
     * @return void
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        $whence = (int) $whence;

        if (!isset($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }
        if (!$this->seekable) {
            throw new RuntimeException('Stream is not seekable');
        }
        if (\fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek to stream position '
                . $offset . ' with whence ' . \var_export($whence, true));
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @link   http://www.php.net/manual/en/function.fseek.php
     * @see    seek()
     * @throws RuntimeException on failure.
     *
     * @return void
     */
    public function rewind()
    {
        $this->seek(0);
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * Write data to the stream.
     *
     * @param string $string The string that is to be written.
     *
     * @return int Returns the number of bytes written to the stream.
     * @throws RuntimeException on failure.
     */
    public function write($string)
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }
        if (!$this->writable) {
            throw new RuntimeException('Cannot write to a non-writable stream');
        }

        // We can't know the size after writing anything
        $this->size = null;
        $result = \fwrite($this->resource, $string);
        if ($result === false) {
            throw new RuntimeException('Unable to write to stream');
        }
        return $result;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *     them. Fewer than $length bytes may be returned if underlying stream
     *     call returns fewer bytes.
     *
     * @return string Returns the data read from the stream, or an empty string
     *     if no bytes are available.
     * @throws RuntimeException if an error occurs.
     */
    public function read($length)
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }
        if (!$this->readable) {
            throw new RuntimeException('Cannot read from non-readable stream');
        }
        if ($length < 0) {
            throw new RuntimeException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        $string = \fread($this->resource, $length);
        if ($string === false) {
            throw new RuntimeException('Unable to read from stream');
        }
        return $string;
    }

    /**
     * Returns the remaining contents in a string
     *
     * @return string
     * @throws RuntimeException if unable to read or an error occurs while
     *     reading.
     */
    public function getContents()
    {
        if (!isset($this->resource)) {
            throw new RuntimeException('Stream is detached');
        }

        $contents = false;
        if ($this->readable) {
            $contents = \stream_get_contents($this->resource);
        }
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents');
        }
        return $contents;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @param string $key Specific metadata to retrieve.
     *
     * @return array|mixed|null Returns an associative array if no key is
     *     provided. Returns a specific key value if a key is provided and the
     *     value is found, or null if the key is not found.
     * @link   http://php.net/manual/en/function.stream-get-meta-data.php
     */
    public function getMetadata($key = null)
    {
        if (!isset($this->resource)) {
            return $key ? null : array();
        }
        if ($key === null) {
            return $this->customMetadata + \stream_get_meta_data($this->resource);
        }
        if (isset($this->customMetadata[$key])) {
            return $this->customMetadata[$key];
        }
        $meta = \stream_get_meta_data($this->resource);
        return isset($meta[$key]) ? $meta[$key] : null;
    }

    /**
     * Set resouce
     *
     * @param mixed $value Resource, filepath, or string content to wrap.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function setResource($value)
    {
        if ($value === null || $value === '') {
            $this->resource = \fopen('php://temp', 'wb+');
            return;
        }
        if (\is_resource($value)) {
            $this->resource = $value;
            return;
        }
        if (\is_string($value)) {
            if (
                \preg_match('#(://|[\r\n\x00])#', $value) !== 1
                && \is_file($value)
            ) {
                \set_error_handler(function () {
                });
                $this->resource = \fopen($value, 'r');
                \restore_error_handler();
                if ($this->resource === false) {
                    throw new RuntimeException(\sprintf(
                        'The file %s cannot be opened.',
                        $value
                    ));
                }
                return;
            }
            $this->resource = \fopen('php://temp', 'wb+');
            \fwrite($this->resource, $value);
            \rewind($this->resource);
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            'Expected resource, filename, or string. %s provided',
            \is_object($value) ? \get_class($value) : \gettype($value)
        ));
    }
}
