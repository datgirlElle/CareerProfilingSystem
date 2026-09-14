<?php

require_once __DIR__ . '/_bootstrap.php';

jsonResponse(['user' => Auth::currentUser(), 'csrfToken' => Csrf::token()]);
