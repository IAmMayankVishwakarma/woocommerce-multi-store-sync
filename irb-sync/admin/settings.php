<?php
if (!defined('ABSPATH')) exit;

add_action('admin_init', function(){
 register_setting('irb_sync_group','irb_sync_options');
});

function irb_sync_settings_page(){
$opt=get_option('irb_sync_options',[
'destination_url'=>'','consumer_key'=>'','consumer_secret'=>'','price_difference'=>500]);
?>
<div class="wrap">
<h1>IRB WooCommerce Sync</h1>
<?php if(isset($_GET['settings-updated'])): ?>
<div class="notice notice-success"><p>Settings saved successfully.</p></div>
<?php endif; ?>
<form method="post" action="options.php">
<?php settings_fields('irb_sync_group'); ?>
<table class="form-table">
<tr><th>Destination URL</th><td><input class="regular-text" name="irb_sync_options[destination_url]" value="<?php echo esc_attr($opt['destination_url']);?>"></td></tr>
<tr><th>Consumer Key</th><td><input class="regular-text" name="irb_sync_options[consumer_key]" value="<?php echo esc_attr($opt['consumer_key']);?>"></td></tr>
<tr><th>Consumer Secret</th><td><input class="regular-text" name="irb_sync_options[consumer_secret]" value="<?php echo esc_attr($opt['consumer_secret']);?>"></td></tr>
<tr><th>Price Difference</th><td><input type="number" name="irb_sync_options[price_difference]" value="<?php echo esc_attr($opt['price_difference']);?>"></td></tr>
</table>
<?php submit_button('Save Settings'); ?>
</form>
</div>
<?php }