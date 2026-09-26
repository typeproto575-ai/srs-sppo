<?php
/**
 * Deny direct access if the storage directory is served without its .htaccess.
 */
http_response_code(403);
exit('Forbidden');
