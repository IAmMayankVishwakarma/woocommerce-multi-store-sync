<?php

if (!defined('ABSPATH')) exit;

class IRB_API {

    private $url;
    private $key;
    private $secret;

    public function __construct()
    {
        $opt = get_option('irb_sync_options');

        $this->url = trailingslashit($opt['destination_url']);

        $this->key = $opt['consumer_key'];

        $this->secret = $opt['consumer_secret'];
    }

}