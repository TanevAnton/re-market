@props(['url'])

{{--
    The RIGO header.

    LOGO *AND* WORDMARK, not one or the other. Most mail clients block remote
    images until the reader asks for them, so an image-only header is a broken
    icon on first open — which is the open that decides whether the message
    looks legitimate. The typed wordmark always renders; the mark is the part
    that is nice when it loads.

    PNG, not the site's SVG favicon: Gmail strips SVG entirely, and so do most
    of the others. The file is public/email-logo.png, rendered from that same
    favicon at 128px and displayed at 44 so it stays sharp on a phone.

    Tables and inline styles throughout, because Outlook renders mail with
    Word's engine and understands very little else.
--}}

<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td style="padding-right: 12px; vertical-align: middle;">
<img src="{{ rtrim(config('app.url'), '/') }}/email-logo.png"
     width="44" height="44" alt="{{ trim($slot) }}"
     style="display: block; border: 0; outline: none; text-decoration: none; border-radius: 10px;">
</td>
<td style="vertical-align: middle;">
<span style="font-size: 26px; font-weight: 700; letter-spacing: 0.10em; color: #047857; font-family: Arial, Helvetica, sans-serif;">{{ trim($slot) }}</span>
</td>
</tr>
</table>
</a>
</td>
</tr>
