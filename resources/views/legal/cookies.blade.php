{{--
    Cookie policy, and the reason there is no banner.

    ЗЕС чл. 4б / ePrivacy Art. 5(3) require prior consent for storage that is
    NOT strictly necessary. Everything this site currently sets is either
    strictly necessary (session, CSRF) or a preference the user set themselves
    by pressing a button (theme) - both exempt.

    A banner asking permission for cookies that need no permission would be
    theatre that costs conversions and trains people to click through consent
    dialogs without reading them.

    THE MOMENT this stops being true - analytics, an embedded video, a chat
    widget, any third-party script - a real consent mechanism becomes required
    and this page has to change with it.
--}}
<x-legal-page
    title="Бисквитки"
    lead="Кратката версия: използваме само технически необходими бисквитки, затова няма банер за съгласие.">

    <h2>Защо няма банер</h2>
    <p>
        Съгласие се изисква за съхранение на информация в устройството ти, което <em>не е</em>
        строго необходимо за услугата — чл. 4б от Закона за електронните съобщения. Всичко, което
        този сайт записва, е или строго необходимо, или настройка, която ти сам си избрал с
        натискане на бутон. За такива не се изисква съгласие.
    </p>
    <p>
        Не използваме бисквитки за реклама, за проследяване между сайтове или за профилиране.
        Ако това някога се промени, ще видиш истински избор — с еднакво лесен отказ — преди
        каквото и да било да бъде записано.
    </p>

    <h2>Какво записваме</h2>

    <table>
        <thead>
            <tr><th>Име</th><th>За какво</th><th>Срок</th></tr>
        </thead>
        <tbody>
            <tr>
                {{-- Read from config: the name is derived from APP_NAME, and a
                     hardcoded one here would quietly become a false statement
                     the day that changes. --}}
                <td class="font-mono text-xs">{{ config('session.cookie') }}</td>
                <td>Поддържа те влязъл в профила и свързва заявките ти в една сесия.
                    Без нея не можеш да влезеш.</td>
                <td>До затваряне на браузъра или до 2 часа бездействие</td>
            </tr>
            <tr>
                <td class="font-mono text-xs">XSRF-TOKEN</td>
                <td>Защита срещу подправяне на заявки (CSRF). Без нея всеки друг сайт би могъл
                    да извършва действия от твое име.</td>
                <td>Колкото сесията</td>
            </tr>
            <tr>
                <td class="font-mono text-xs">theme</td>
                <td>Запомня дали си избрал светла или тъмна тема. Записва се само ако натиснеш
                    бутона за тема — по подразбиране следваме настройката на устройството ти
                    и не записваме нищо.</td>
                <td>1 година</td>
            </tr>
        </tbody>
    </table>

    <p>
        Нито една от тях не съдържа лични данни в четим вид и нито една не се споделя с трети лица.
    </p>

    <h2>Ако не ги искаш</h2>
    <p>
        Всеки браузър позволява блокиране и изтриване на бисквитки за конкретен сайт. Ако блокираш
        тези, влизането в профил няма да работи — сесията е това, което те държи влязъл. Разглеждането
        на обяви ще продължи да работи нормално.
    </p>

    <h2>Външни ресурси</h2>
    <p>
        Ако защитата срещу ботове е активирана, страниците за регистрация, вход, публикуване на обява,
        оферта и сигнал зареждат Cloudflare Turnstile. Turnstile е избран именно защото
        <strong>не поставя бисквитки и не проследява между сайтове</strong> — за разлика от
        обичайните алтернативи.
    </p>
    <p>
        Не вграждаме видеа, шрифтове, карти, чат приложения или бутони за социални мрежи от
        външни сайтове.
    </p>
</x-legal-page>
