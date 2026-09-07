{{--
    DSA Arts. 11 and 12, plus ЗЕТ чл. 4 identification.

    Art. 11 and Art. 12 require SEPARATE published contact points - one for
    authorities, one for users. They may resolve to the same mailbox, but both
    have to be published, and the user-facing one must not be bot-only.

    Nothing here has to be registered or notified anywhere. КРС is the Bulgarian
    Digital Services Coordinator, but the obligation is to PUBLISH, not to file.
--}}
<x-legal-page
    title="Контакти и точки за връзка"
    lead="Кой стои зад платформата, как да се свържеш с нас и как органите могат да ни намерят.">

    <h2>Доставчик на услугата</h2>

    <dl>
        <dt>Наименование</dt>
        <dd>{{ config('legal.entity.name') }}</dd>

        <dt>ЕИК</dt>
        <dd><x-legal-value :value="config('legal.entity.eik')" env="LEGAL_ENTITY_EIK" /></dd>

        @if (config('legal.entity.vat'))
            <dt>ДДС номер</dt>
            <dd>{{ config('legal.entity.vat') }}</dd>
        @endif

        <dt>Седалище и адрес на управление</dt>
        <dd><x-legal-value :value="config('legal.entity.address')" env="LEGAL_ENTITY_ADDRESS" /></dd>

        <dt>Представляващ</dt>
        <dd><x-legal-value :value="config('legal.entity.manager')" env="LEGAL_ENTITY_MANAGER" /></dd>
    </dl>

    <h2>Точка за връзка за потребители</h2>
    <p>
        Регламент (ЕС) 2022/2065 (Акт за цифровите услуги), чл. 12. Пиши ни на този адрес
        за всичко, свързано с платформата — проблем с профил, обява, оферта или решение,
        което сме взели.
    </p>

    <dl>
        <dt>Имейл</dt>
        <dd><x-legal-value :value="config('legal.contact.users')" env="LEGAL_CONTACT_USERS" /></dd>

        @if (config('legal.contact.phone'))
            <dt>Телефон</dt>
            <dd>{{ config('legal.contact.phone') }}</dd>
        @endif
    </dl>

    <p>
        Съобщенията на този адрес се четат от човек. Не използваме единствено автоматизирани
        средства за комуникация с потребители — това е изрично изискване на чл. 12, § 1.
    </p>

    <h2>Точка за връзка за органи</h2>
    <p>
        Акт за цифровите услуги, чл. 11. Адрес за електронна комуникация с органите на
        държавите членки, с Европейската комисия и със Съвета за цифрови услуги.
    </p>

    <dl>
        <dt>Имейл</dt>
        <dd><x-legal-value :value="config('legal.contact.authorities')" env="LEGAL_CONTACT_AUTHORITIES" /></dd>

        <dt>Езици</dt>
        <dd>{{ implode(', ', config('legal.contact.languages')) }}</dd>
    </dl>

    <h2>Надзорни органи</h2>

    <h3>Цифрови услуги</h3>
    <p>
        Координатор за цифрови услуги за България е
        <a href="https://crc.bg" target="_blank" rel="noopener">Комисията за регулиране на съобщенията (КРС)</a>.
    </p>

    <h3>Лични данни</h3>
    <p>
        Надзорен орган по защита на личните данни е
        <a href="https://cpdp.bg" target="_blank" rel="noopener">Комисията за защита на личните данни (КЗЛД)</a>.
        Имаш право на жалба до нея по всяко време — виж
        <a href="{{ route('legal.privacy') }}" wire:navigate>Политиката за поверителност</a>.
    </p>

    <h3>Защита на потребителите</h3>
    <p>
        <a href="https://kzp.bg" target="_blank" rel="noopener">Комисия за защита на потребителите (КЗП)</a>.
        Обърни внимание: защитата по Закона за защита на потребителите се прилага при покупка
        от търговец, не от частно лице. На всяка обява е отбелязано кое от двете е продавачът.
    </p>

    <h2>Сигнали за незаконно съдържание</h2>
    <p>
        Не изпращай сигнали на имейл — има бутон „Докладвай“ под всяка обява и всеки профил,
        и той е по-бърз. Виж <a href="{{ route('legal.notice') }}" wire:navigate>как разглеждаме сигнали</a>.
    </p>
</x-legal-page>
