<?php

it('redirects guests from / to login', function () {
    $this->get('/')->assertRedirect('/login');
});
