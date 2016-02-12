<?php

namespace Starsquare\Letterboxd;

class Logger {

    protected $stream;

    public function __construct($stream) {
        if (is_string($stream)) {
            $stream = fopen($stream, 'w');
        }

        $this->stream = $stream;
    }

    public function __destruct() {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function log($message, $level) {
        fwrite($this->stream, "[$level] $message\n");
    }

    public function debug($message) {
        return $this->log($message, strtoupper(__FUNCTION__));
    }

    public function info($message) {
        return $this->log($message, strtoupper(__FUNCTION__));
    }

    public function notice($message) {
        return $this->log($message, strtoupper(__FUNCTION__));
    }

    public function warn($message) {
        return $this->log($message, strtoupper(__FUNCTION__));
    }

    public function err($message) {
        return $this->log($message, strtoupper(__FUNCTION__));
    }
}
