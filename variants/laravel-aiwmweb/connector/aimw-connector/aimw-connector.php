<?php
/**
 * Plugin Name: AIMW Connector
 * Description: Signed, scoped semantic operations for Laravel AIWMWeb.
 * Version: 0.1.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

final class AIMW_Connector_V1 {
    private const NS = 'aimw/v1';
    private const VERSION = '1';
    private const CAPABILITIES = ['health','content.read','content.update','seo.read','seo.write','audit.local','connector.manage'];

    public static function boot(): void { add_action('rest_api_init',[self::class,'routes']); }
    public static function routes(): void {
        register_rest_route(self::NS,'/pair',['methods'=>'POST','callback'=>[self::class,'pair'],'permission_callback'=>fn()=>current_user_can('manage_options')]);
        register_rest_route(self::NS,'/health',['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>[self::class,'authorize']]);
        register_rest_route(self::NS,'/content',['methods'=>'GET','callback'=>[self::class,'content'],'permission_callback'=>[self::class,'authorize']]);
        register_rest_route(self::NS,'/content/(?P<type>post|page)/(?P<id>\d+)',['methods'=>'GET','callback'=>[self::class,'read'],'permission_callback'=>[self::class,'authorize']]);
        register_rest_route(self::NS,'/execute',['methods'=>'POST','callback'=>[self::class,'execute'],'permission_callback'=>[self::class,'authorize']]);
        register_rest_route(self::NS,'/rotate',['methods'=>'POST','callback'=>[self::class,'rotate'],'permission_callback'=>[self::class,'authorize']]);
        register_rest_route(self::NS,'/disconnect',['methods'=>'POST','callback'=>[self::class,'disconnect'],'permission_callback'=>[self::class,'authorize']]);
    }

    public static function pair(WP_REST_Request $request) {
        $platform=rtrim(esc_url_raw($request['platform_url']),'/'); $token=sanitize_text_field($request['pairing_token']);
        if(!$platform || !$token) return new WP_Error('invalid_pairing','Platform URL and pairing token are required.',['status'=>422]);
        $identity=wp_generate_uuid4();
        $response=wp_remote_post($platform.'/api/connector/pair',['timeout'=>20,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['token'=>$token,'identity'=>$identity,'protocol_version'=>self::VERSION,'capabilities'=>self::CAPABILITIES])]);
        if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==201) return new WP_Error('pairing_failed','Laravel pairing was rejected.',['status'=>502]);
        $payload=json_decode(wp_remote_retrieve_body($response),true);
        if(empty($payload['secret'])) return new WP_Error('pairing_failed','Pairing response did not include a secret.',['status'=>502]);
        update_option('aimw_connector',['platform_url'=>$platform,'identity'=>$identity,'secret'=>$payload['secret'],'protocol_version'=>self::VERSION,'enabled_scopes'=>$payload['enabled_scopes']??['health','content.read','seo.read','connector.manage'],'revoked'=>false],false);
        self::audit('paired',['identity'=>$identity]);
        return ['paired'=>true,'identity'=>$identity,'protocol_version'=>self::VERSION,'capabilities'=>self::CAPABILITIES];
    }

    public static function authorize(WP_REST_Request $request) {
        $config=get_option('aimw_connector',[]); if(empty($config['secret'])||!empty($config['revoked'])) return new WP_Error('connector_inactive','Connector is not active.',['status'=>401]);
        $headers=array_change_key_case($request->get_headers(),CASE_LOWER); $one=fn($name)=>is_array($headers[$name]??null)?($headers[$name][0]??''):($headers[$name]??'');
        $values=['version'=>$one('x-aimw-version'),'tenant'=>$one('x-aimw-tenant'),'site'=>$one('x-aimw-site'),'connector'=>$one('x-aimw-connector'),'timestamp'=>$one('x-aimw-timestamp'),'nonce'=>$one('x-aimw-nonce'),'request'=>$one('x-aimw-request-id'),'correlation'=>$one('x-aimw-correlation-id'),'operation'=>$one('x-aimw-operation-id'),'scope'=>$one('x-aimw-scope'),'signature'=>$one('x-aimw-signature')];
        if(in_array('',array_values($values),true)||$values['version']!==self::VERSION||$values['connector']!==$config['identity']) return new WP_Error('invalid_protocol','Protocol identity/version mismatch.',['status'=>401]);
        if(abs(time()-(int)$values['timestamp'])>300) return new WP_Error('expired_request','Request timestamp expired.',['status'=>401]);
        $required=self::required_scopes($request); $primary=end($required);
        if($values['scope']!==$primary) return new WP_Error('scope_mismatch','Signed scope does not match the operation-required scope.',['status'=>403]);
        foreach($required as $scope)if(!in_array($scope,$config['enabled_scopes']??[],true))return new WP_Error('scope_disabled','Required connector scope is disabled: '.$scope.'.',['status'=>403]);
        if(get_transient('aimw_nonce_'.hash('sha256',$values['nonce']))) return new WP_Error('replay','Nonce already used.',['status'=>409]);
        $path='/wp-json'.$request->get_route(); $query=$request->get_query_params(); if($query)$path.='?'.http_build_query($query);
        $canonical=implode("\n",[strtoupper($request->get_method()),$path,hash('sha256',$request->get_body()),$values['version'],$values['tenant'],$values['site'],$values['connector'],$values['timestamp'],$values['nonce'],$values['request'],$values['correlation'],$values['operation'],$values['scope']]);
        if(!hash_equals(hash_hmac('sha256',$canonical,$config['secret']),$values['signature'])) return new WP_Error('invalid_signature','Invalid request signature.',['status'=>401]);
        set_transient('aimw_nonce_'.hash('sha256',$values['nonce']),1,300); $request->set_attribute('aimw_protocol',$values); return true;
    }

    public static function health(): array { return ['status'=>'healthy','protocol_version'=>self::VERSION,'capabilities'=>self::CAPABILITIES,'wordpress'=>get_bloginfo('version')]; }
    public static function content(WP_REST_Request $request): array {
        $args=['post_type'=>['post','page'],'post_status'=>['publish','draft','private'],'posts_per_page'=>100,'orderby'=>'modified','order'=>'ASC']; if($request['modified_after'])$args['date_query']=[['column'=>'post_modified_gmt','after'=>sanitize_text_field($request['modified_after'])]];
        return ['items'=>array_map([self::class,'serialize'],get_posts($args))];
    }
    public static function read(WP_REST_Request $request) { $post=get_post((int)$request['id']); if(!$post||$post->post_type!==$request['type'])return new WP_Error('not_found','Content not found.',['status'=>404]); return self::serialize($post); }
    public static function execute(WP_REST_Request $request) {
        $protocol=$request->get_attribute('aimw_protocol'); $operation=$protocol['operation']; $prior=get_transient('aimw_operation_'.hash('sha256',$operation)); if($prior)return $prior;
        $payload=$request->get_json_params(); $post=get_post((int)($payload['remote_id']??0)); if(!$post||!in_array($post->post_type,['post','page'],true)||$post->post_type!==($payload['resource_type']??''))return new WP_Error('not_found','Content not found.',['status'=>404]);
        $changes=array_intersect_key((array)($payload['changes']??[]),array_flip(['title','content','slug','seo_title','seo_description'])); $before=self::serialize($post); $update=['ID'=>$post->ID]; if(isset($changes['title']))$update['post_title']=wp_kses_post($changes['title']); if(isset($changes['content']))$update['post_content']=wp_kses_post($changes['content']); if(isset($changes['slug']))$update['post_name']=sanitize_title($changes['slug']);
        $result=wp_update_post($update,true); if(is_wp_error($result))return $result; if(isset($changes['seo_title']))update_post_meta($post->ID,'_yoast_wpseo_title',sanitize_text_field($changes['seo_title'])); if(isset($changes['seo_description']))update_post_meta($post->ID,'_yoast_wpseo_metadesc',sanitize_textarea_field($changes['seo_description']));
        $after=self::serialize(get_post($post->ID)); $response=['operation_id'=>$operation,'before'=>$before,'after'=>$after,'status'=>'succeeded']; set_transient('aimw_operation_'.hash('sha256',$operation),$response,DAY_IN_SECONDS); self::audit('executed',['operation_id'=>$operation,'post_id'=>$post->ID]); return $response;
    }
    public static function rotate(WP_REST_Request $request) { $config=get_option('aimw_connector',[]); $secret=(string)($request->get_json_params()['new_secret']??''); if(strlen($secret)<32)return new WP_Error('invalid_secret','Replacement secret is invalid.',['status'=>422]); $config['secret']=$secret; update_option('aimw_connector',$config,false); self::audit('secret_rotated',[]); return ['rotated'=>true]; }
    public static function disconnect() { $config=get_option('aimw_connector',[]); $config['revoked']=true; update_option('aimw_connector',$config,false); self::audit('disconnected',[]); return ['disconnected'=>true]; }
    private static function required_scopes(WP_REST_Request $request): array {
        $route=$request->get_route();
        if($route==='/'.self::NS.'/health')return ['health'];
        if($route==='/'.self::NS.'/content'||preg_match('#^/'.self::NS.'/content/(post|page)/\d+$#',$route))return ['content.read'];
        if($route==='/'.self::NS.'/rotate'||$route==='/'.self::NS.'/disconnect')return ['connector.manage'];
        if($route==='/'.self::NS.'/execute'){$changes=(array)(($request->get_json_params()['changes']??[]));$required=[];if(array_intersect(['title','content','slug'],array_keys($changes)))$required[]='content.update';if(array_intersect(['seo_title','seo_description'],array_keys($changes)))$required[]='seo.write';if($required)return $required;}
        return ['deny'];
    }
    private static function serialize(WP_Post $post): array { preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is',$post->post_content,$m); $media=get_attached_media('image',$post->ID); return ['type'=>$post->post_type,'id'=>$post->ID,'slug'=>$post->post_name,'title'=>get_the_title($post),'content'=>$post->post_content,'excerpt'=>$post->post_excerpt,'headings'=>array_map('wp_strip_all_tags',$m[1]??[]),'taxonomy'=>['categories'=>wp_get_post_categories($post->ID),'tags'=>wp_get_post_tags($post->ID,['fields'=>'ids'])],'media'=>array_map(fn($item)=>['id'=>$item->ID,'url'=>wp_get_attachment_url($item->ID)],array_values($media)),'seo_title'=>(string)get_post_meta($post->ID,'_yoast_wpseo_title',true),'seo_description'=>(string)get_post_meta($post->ID,'_yoast_wpseo_metadesc',true),'modified_at'=>get_post_modified_time(DATE_ATOM,true,$post)]; }
    private static function audit(string $event,array $data): void { $events=get_option('aimw_connector_audit',[]); $events[]=['event'=>$event,'data'=>$data,'at'=>gmdate(DATE_ATOM),'user_id'=>get_current_user_id()]; update_option('aimw_connector_audit',array_slice($events,-500),false); }
}
AIMW_Connector_V1::boot();
