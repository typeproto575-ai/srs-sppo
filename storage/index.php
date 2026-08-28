<?php
// Extra guard in case .htaccess isn't honored (e.g. misconfigured vhost).
http_response_code(403);
exit('Forbidden');
