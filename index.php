<?php

class DeepCopy
{
    protected $from;
    protected $to;
    protected $mode;

    public function __construct($from, $to, $mode = 'skip')
    {
        $this->from      = realpath($from);
        $this->to        = rtrim(preg_replace("/${from}$/", $to, $this->from), '/');

        if (!$this->from) {
            throw new \Exception("Source dir does not exist", 500);
        }

        if (!is_dir($this->from)) {
            throw new \Exception("Source dir must be a folder", 500);
        }

        if (!in_array($mode, ['skip', 'overwrite', 'merge'])) {
            throw new \Exception("Unknown mode `$mode`", 500);
        }

        $this->mode = $mode;

        $this->clone($this->from);
    }

    protected function clone($dir)
    {
        @mkdir(str_replace($this->from, $this->to, $dir), 0777, true);

        foreach (glob($dir.'/{,.}[!.,!..]*', GLOB_MARK|GLOB_BRACE) as $file) {
            if (basename($file) === '.git') {
                continue;
            }

            // Fix some odd behaviour with `//` slashes
            $file = str_replace('//', '/', $file);

            if (is_dir($file)) {
                $this->clone($file);
            } else {
                $fileTo = str_replace($this->from, $this->to, $file);

                if (
                    !file_exists($fileTo)
                    ||
                    $this->mode === 'overwrite'
                    ||
                    ($this->mode === 'merge' && filemtime($file) < filemtime($fileTo))
                ) {
                    if (!@copy($file, $fileTo)) {
                        throw new \Exception(sprintf("Failed to copy `%s` to `%s`", basename($file), str_replace($this->from, $this->to, $file)), 500);
                    }
                }
            }
        }
    }
}

header('Content-Type: text/plain');

new DeepCopy('example', '', (isset($_GET['mode']) ? $_GET['mode'] : 'merge'));

echo 'OK. Please reload';
