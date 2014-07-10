<?php

class Device {
  
    const iOS = 0;
    const Android = 1;
    const Desktop = 3;
  
    public static function currentDevice() {
        $agent = $_SERVER['HTTP_USER_AGENT'];
            
        if (strpos($agent, 'iPad') !== false || strpos($agent, 'iPhone') !== false) {
            return self::iOS;
        }
        else if (strpos($agent, 'Android') !== false) {
            return self::Android;
        }
        else {
            return self::Desktop;
        }
    }
    
}

?>