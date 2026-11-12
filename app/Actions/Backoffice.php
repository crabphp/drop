<?php

declare(strict_types=1);

namespace App\Actions;

use App\AdminSessions;
use App\Files;
use App\Http\LoginInput;
use App\Pages;
use App\RateLimits;
use Crab\Contracts\Http\Request;
use Crab\Routing\FromQuery;
use Crab\Http\Response;
use Crab\Validation\Range;

final readonly class Backoffice
{
    public function __construct(private AdminSessions $sessions, private Files $files, private Pages $pages, private RateLimits $limits) {}

    public function index(Request $request, #[FromQuery] #[Range(min: 1, max: 200)] int $page = 1): Response
    {
        if (!$this->sessions->authenticated($request)) {
            return $this->pages->login();
        }
        return $this->pages->backoffice($this->files->current($page), $page);
    }

    public function login(LoginInput $input, Request $request): Response
    {
        $this->limits->hit('admin-login', $request->ip(), 5, 900);
        $cookie = $this->sessions->login($input->token, $request);
        return $cookie === null
            ? $this->pages->login('That token is not correct.', 401)
            : Response::redirect('/backoffice', 303)->withCookie($cookie);
    }

    public function logout(Request $request): Response
    {
        return Response::redirect('/backoffice', 303)->withCookie($this->sessions->logout($request));
    }
}
