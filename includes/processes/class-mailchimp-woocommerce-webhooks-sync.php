<?php

/**
 * Created by Vextras.
 *
 * Name: Alejandro Giraldo
 * Email: alejandro@vextras.com
 * Date: 04/02/16
 * Time: 10:55 PM
 */
class MailChimp_WooCommerce_WebHooks_Sync extends Mailchimp_Woocommerce_Job
{
    /**
     * Handle job
     * @return void
     */
    public function handle()
    {
        $this->subscribeWebhook();
    }
    /**
     * Subscribe mailchimp webhook
     * @return void|bool 
     */
    public function subscribeWebhook(){
        try {
            // for some reason the webhook url does not work with ?rest_route style, permalinks should be defined also 
            if( mailchimp_is_configured() && !mailchimp_get_webhook_url() && get_option( 'permalink_structure' ) !== '' ){
                
                    $key = mailchimp_get_data('webhook.token');
                    if( !$key ){
                        $key = mailchimp_create_webhook_token();
                        mailchimp_set_data('webhook.token', $key);  
                    }

                    $url = mailchimp_build_webhook_url( $key );
                    
                    //requesting api webhooks subscription
                    $api = mailchimp_get_api();
                    $webhook = $api->webHookSubscribe( mailchimp_get_list_id(), $url ) ;
                    
                    //if no errors let save the url 
                    mailchimp_set_webhook_url($webhook['url']);
            }
        } catch (\Throwable $e) {
            mailchimp_error('webhook', $e->getMessage());
        }
        return false;
    }
}