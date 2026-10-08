<?php

// Local XAMPP convenience entry point.
// Laravel's web front controller belongs in /public; this prevents Apache
// from showing the project directory when users open /masar directly.
header('Location: public/', true, 302);
exit;
