<?php

namespace Tests\Unit;

use App\Support\TextLanguage;
use PHPUnit\Framework\TestCase;

/** A brief is made in the language it is written in (#11, 2026-10-12). */
class TextLanguageTest extends TestCase
{
    public function test_briefs_are_read_in_their_own_language(): void
    {
        $this->assertSame('pt', TextLanguage::detect('Sabe quantas vezes por dia perguntavam no WhatsApp da minha Lotérica se a Mega tinha acumulado? Eu contei numa semana. Passou de oitenta. Oitenta vezes a mesma pergunta, e a operadora parando o caixa da Lotérica pra responder.'));
        $this->assertSame('en', TextLanguage::detect('A founder explaining how wyvstudio helped his team in making short form ads and urged them to visit the site.'));
        $this->assertSame('es', TextLanguage::detect('Un anuncio para mi panadería: el pan es fresco cada mañana y lo hacemos con masa madre, para los que buscan algo más.'));
        $this->assertSame('fr', TextLanguage::detect('Une publicité pour notre café: nous torréfions les grains chaque semaine et vous les recevez chez vous avec une note.'));
        $this->assertSame('de', TextLanguage::detect('Eine Anzeige für unser Café: wir rösten die Bohnen jede Woche und du bekommst sie mit der Post, frisch und auch günstig.'));
        $this->assertSame('ja', TextLanguage::detect('新しいコーヒーの広告を作ってください。毎週焙煎したての豆をお届けします。'));
        $this->assertNull(TextLanguage::detect('Nike shoes ad'), 'too short to tell');
        $this->assertNull(TextLanguage::detect(''));
    }
}
