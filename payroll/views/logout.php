<?php
if (is_post()) {
    Auth::logout();
}
redirect('login');
