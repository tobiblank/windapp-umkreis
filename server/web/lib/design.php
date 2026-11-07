<?php
/**
 * Hell / Dunkel (seit 7.11.2026).
 *
 * Standard: wie das Geraet (prefers-color-scheme). Im Fuss laesst sich das auf
 * "Hell" oder "Dunkel" festlegen; die Wahl steht nur im Browser (localStorage,
 * Schluessel DESIGN_SCHLUESSEL) und setzt data-theme="light|dark" auf <html>.
 * stil.css kennt beide Wege. Das Skript im Kopf laeuft vor dem ersten Zeichnen,
 * damit die Seite nicht kurz im falschen Design aufblitzt.
 */

const DESIGN_SCHLUESSEL = 'wohinfahrma_design';
const DESIGN_FARBEN = ['light' => '#eef3f4', 'dark' => '#0f1e24'];   // = --grund in stil.css

/** <meta theme-color> und Skript fuer den <head> */
function design_kopf(): string
{
    $s = json_encode(DESIGN_SCHLUESSEL);
    $f = json_encode(DESIGN_FARBEN);
    return '<meta name="theme-color" content="' . DESIGN_FARBEN['light'] . '" media="(prefers-color-scheme: light)">'
        . '<meta name="theme-color" content="' . DESIGN_FARBEN['dark'] . '" media="(prefers-color-scheme: dark)">'
        . "<script>(function(){var w=null;try{w=localStorage.getItem($s)}catch(e){}"
        . "if(w!=='light'&&w!=='dark'){return}document.documentElement.setAttribute('data-theme',w);"
        . "var f=$f;document.querySelectorAll('meta[name=theme-color]').forEach(function(m){m.removeAttribute('media');m.setAttribute('content',f[w])})})();</script>";
}

/** Schalter fuer den Fuss: ohne JavaScript unsichtbar (dann gilt das Geraet) */
function design_schalter(): string
{
    $s = json_encode(DESIGN_SCHLUESSEL);
    $f = json_encode(DESIGN_FARBEN);
    $knoepfe = '';
    foreach (['auto' => 'Wie Gerät', 'light' => 'Hell', 'dark' => 'Dunkel'] as $wert => $text) {
        $knoepfe .= '<button type="button" data-design="' . $wert . '" aria-pressed="false">' . $text . '</button>';
    }
    return '<div class="design" id="design" role="group" aria-label="Design" hidden><span>Design</span>' . $knoepfe . '</div>'
        . "<script>(function(){var box=document.getElementById('design');if(!box){return}"
        . "var S=$s,F=$f,html=document.documentElement;"
        . "var metas=[].slice.call(document.querySelectorAll('meta[name=theme-color]'));"
        . "function zeigen(w){[].forEach.call(box.querySelectorAll('button'),function(b){b.setAttribute('aria-pressed',b.getAttribute('data-design')===w?'true':'false')})}"
        . "function setzen(w){if(w==='light'||w==='dark'){html.setAttribute('data-theme',w);try{localStorage.setItem(S,w)}catch(e){}"
        . "metas.forEach(function(m){m.removeAttribute('media');m.setAttribute('content',F[w])})}"
        . "else{html.removeAttribute('data-theme');try{localStorage.removeItem(S)}catch(e){}"
        . "metas.forEach(function(m,i){var k=i?'dark':'light';m.setAttribute('media','(prefers-color-scheme: '+k+')');m.setAttribute('content',F[k])})}zeigen(w)}"
        . "box.addEventListener('click',function(e){var b=e.target.closest('button');if(b){setzen(b.getAttribute('data-design'))}});"
        . "zeigen(html.getAttribute('data-theme')||'auto');box.hidden=false})();</script>";
}
