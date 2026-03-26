<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CaptchaService
{
    public function generate(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $answer = (string) ($a + $b);
        $question = sprintf('%d + %d = ?', $a, $b);

        $key = 'captcha:'.Str::uuid()->toString();
        Cache::put($key, $answer, now()->addMinutes(5));

        return [
            'key' => $key,
            'image' => 'data:image/svg+xml;base64,'.base64_encode($this->buildSvg($question)),
            'expires_in' => 300,
        ];
    }

    public function verify(string $key, string $code): bool
    {
        $cached = Cache::get($key);
        if (! $cached) {
            return false;
        }

        Cache::forget($key);

        return trim($cached) === trim($code);
    }

    private function buildSvg(string $question): string
    {
        $noise = '';
        for ($i = 0; $i < 8; $i++) {
            $x1 = random_int(0, 160);
            $x2 = random_int(0, 160);
            $y1 = random_int(0, 50);
            $y2 = random_int(0, 50);
            $noise .= sprintf(
                "<line x1='%d' y1='%d' x2='%d' y2='%d' stroke='#d9e6ff' stroke-width='1'/>",
                $x1,
                $y1,
                $x2,
                $y2
            );
        }

        return "<svg xmlns='http://www.w3.org/2000/svg' width='160' height='50' viewBox='0 0 160 50'>"
            ."<rect width='160' height='50' fill='#f3f8ff' rx='6'/>"
            .$noise
            ."<text x='18' y='32' font-size='20' fill='#1f3a8a' font-family='Arial, sans-serif'>"
            .htmlspecialchars($question, ENT_QUOTES, 'UTF-8')
            .'</text>'
            .'</svg>';
    }
}

