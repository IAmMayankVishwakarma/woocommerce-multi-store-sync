<?php

if (!defined('ABSPATH')) {
    exit;
}

class IRB_API_Client
{
    private $base_url;
    private $consumer_key;
    private $consumer_secret;

    public function __construct()
    {
        $this->base_url = rtrim(get_option('irb_destination_url'), '/');
        $this->consumer_key = get_option('irb_consumer_key');
        $this->consumer_secret = get_option('irb_consumer_secret');
    }
}