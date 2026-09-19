<?php

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\VerifyEmailNotice;
use App\Livewire\Auth\VerifyPhone;
use App\Livewire\AppleSection;
use App\Livewire\BrowseListings;
use App\Livewire\BuildGuide;
use App\Livewire\Bundles\ManageBundle;
use App\Livewire\Bundles\MyBundles;
use App\Livewire\Bundles\ShowBundle;
use App\Livewire\Favorites\MyFavorites;
use App\Livewire\Home;
use App\Livewire\SavedSearches\MySearches;
use App\Livewire\ShowPart;
use App\Livewire\Valuation;
use App\Livewire\Listings\CreateListing;
use App\Livewire\Listings\EditListing;
use App\Livewire\Listings\MyListings;
use App\Livewire\Deals\MyDeals;
use App\Livewire\Messages\Inbox;
use App\Livewire\Moderation\CatalogueQueue;
use App\Livewire\Moderation\Queue as ModerationQueue;
use App\Livewire\Messages\ShowThread;
use App\Livewire\Offers\OfferInbox;
use App\Livewire\Profile\EditProfile;
use App\Livewire\Profile\ShowProfile;
use App\Livewire\ShowListing;
use App\Http\Controllers\Sitemap;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Public URLs are Bulgarian, because they are read by Bulgarian users and by
 * Google in Bulgarian. Listings are addressed by uuid rather than id so the
 * site never advertises how few (or how many) listings it really has.
 */

// --- public ---------------------------------------------------------------
// Browsing stays open. Gating reading behind signup is how a marketplace with
// no users stays a marketplace with no users.
Route::get('/', Home::class)->name('home');

/*
 * Browse moved off "/" when the home page arrived. The route NAME is unchanged,
 * so every link, redirect and test that pointed at route('browse') still lands
 * on the listing grid - only the URL is new.
 */
Route::get('/obiavi', BrowseListings::class)->name('browse');
Route::get('/obiava/{listing}', ShowListing::class)->name('listing');
Route::get('/profil/{username}', ShowProfile::class)->name('profile');

/*
 * „Комплект" rather than „набор" or „пакет": it is the word a Bulgarian seller
 * already writes in the description when they are selling a whole machine.
 *
 * Public, and deliberately NOT under /obiava. A bundle is a grouping of
 * listings, not a listing — giving it a listing URL is the first step towards
 * something treating it as one, and mark-sold, the offer floor and the
 * moderation gate all assume a listing is one item.
 *
 * whereUuid is load-bearing, not decoration. This route is registered before
 * `/komplekt/nov`, which lives in the authenticated group further down, and
 * Laravel matches in registration order — without the constraint, „nov" would
 * be read as a bundle uuid and the create screen would answer 404.
 */
Route::get('/komplekt/{bundle}', ShowBundle::class)->whereUuid('bundle')->name('bundle');

/*
 * The catalogue landing pages - the entire organic-search strategy.
 *
 * Nobody searches for this site by name. They search for "rtx 4070 цена бг",
 * and a listing cannot answer that: it is one asking price and it disappears
 * when the card sells, taking its rankings with it. These pages are permanent,
 * accumulate links, and keep working when there is nothing for sale.
 *
 * `/model/` rather than `/chast/`: it is what the page is about, it is short,
 * and it reads the same in both alphabets.
 */
Route::get('/model/{part}', ShowPart::class)->name('part');

/*
 * „Колко струва техниката ми" - the same catalogue data pointed at a seller.
 *
 * Supply is the bottleneck on a marketplace this young, and the moment
 * somebody decides to sell a card is the moment before they look up what it is
 * worth. Today that search ends on a competitor or in a 2021 forum thread.
 * This is the page that ends it here, and it is deliberately public: an
 * acquisition page behind a login acquires nobody.
 *
 * The URL is the query, transliterated. `/kolko-struva` is what somebody would
 * recognise in a result list; `/valuation` is a word no Bulgarian seller types.
 */
Route::get('/kolko-struva', Valuation::class)->name('valuation');

/*
 * „Специален раздел" for Apple — one door in front of three ordinary
 * categories rather than a fourth category of its own.
 *
 * iPhone, iPad and MacBook already have their own schemas and their own
 * catalogue; this is the entrance for somebody who knows the brand before they
 * know the device, and the only surface that can rank for „apple втора
 * употреба" - a phrase no individual listing will ever answer.
 *
 * The URL is the word people type. It is the same in both alphabets, which is
 * why it is one of the few routes here that is not Bulgarian.
 */
Route::get('/apple', AppleSection::class)->name('apple');

/*
 * „Сглоби компютър от втора употреба."
 *
 * The compatibility links have existed on every listing and model page for a
 * while and nobody sees them, because they sit beneath a listing somebody
 * arrived at already knowing what they wanted - they answer a question the
 * visitor did not come with. This page asks it for them.
 *
 * It is also the only surface that can rank for „сглоби компютър втора
 * употреба" or „компютър на части": a listing is one part and vanishes when it
 * sells, a model page is one model, and this page is about the idea - so it
 * survives every listing on it selling, which none of the others do.
 *
 * `/sglobi` rather than `/build` or `/konfigurator`: it is the imperative a
 * Bulgarian actually types, and it promises assembling rather than configuring
 * - which matters, because this page does not configure anything yet.
 */
Route::get('/sglobi', BuildGuide::class)->name('build');

/*
 * Landing pages nobody crawls are worth nothing, so the sitemap ships with
 * them rather than "later". It refuses to serve anything when the deployment
 * is not indexable - see config('remarket.seo.indexable').
 */
Route::get('/sitemap.xml', Sitemap::class)->name('sitemap');

/*
 * The legal pages. Public and unauthenticated on purpose: DSA Art. 11 and 12
 * contact points behind a login would not be published at all, and someone
 * whose photographs were stolen has to be able to read how to report it
 * without first creating an account here.
 *
 * Static views rather than components - no state, no interaction, and they
 * should keep rendering if every other part of the site is broken.
 */
Route::view('/usloviya', 'legal.terms')->name('legal.terms');
Route::view('/poveritelnost', 'legal.privacy')->name('legal.privacy');
Route::view('/biskvitki', 'legal.cookies')->name('legal.cookies');
Route::view('/kontakti', 'legal.contacts')->name('legal.contacts');
Route::view('/signali', 'legal.notice')->name('legal.notice');

// --- guests ---------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/registraciya', Register::class)->name('register');
    Route::get('/vhod', Login::class)->name('login');

    /*
     * Password reset. Until these existed, forgetting a password meant losing
     * the account outright - the site's only proof of identity was the email
     * address and nothing was wired to it that could recover anything.
     *
     * The route NAME `password.reset` is not free choice: it is what the reset
     * link is built from, and what Laravel's own password-confirmation
     * middleware looks for. The URL underneath it is ours.
     */
    Route::get('/zabravena-parola', ForgotPassword::class)->name('password.request');
    Route::get('/nova-parola/{token}', ResetPassword::class)->name('password.reset');
});

// --- authenticated --------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/potvardi-telefon', VerifyPhone::class)->name('phone.verify');

    /*
     * Email verification. These three route NAMES are not optional once User
     * implements MustVerifyEmail: Laravel's built-in VerifyEmail notification
     * builds its signed link from route('verification.verify'), so registering
     * a user without them throws RouteNotFoundException mid-signup.
     */
    Route::get('/potvardi-imeil', VerifyEmailNotice::class)
        ->name('verification.notice');

    Route::get('/potvardi-imeil/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('browse')->with('status', 'Имейлът е потвърден.');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::post('/potvardi-imeil/prati', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Изпратихме нов линк за потвърждение.');
    })->middleware('throttle:6,1')->name('verification.send');
    Route::get('/nastroyki', EditProfile::class)->name('profile.edit');

    /*
     * The shortlist and the standing wants. Neither needs a verified email:
     * they are reading and remembering, not acting on anyone else.
     */
    Route::get('/zapazeni', MyFavorites::class)->name('favorites');
    Route::get('/zapazeni-tarseniya', MySearches::class)->name('searches');

    Route::get('/oferti', OfferInbox::class)->name('offers');
    Route::get('/sdelki', MyDeals::class)->name('deals');

    Route::get('/sabshteniya', Inbox::class)->name('messages');
    Route::get('/sabshteniya/{thread}', ShowThread::class)->name('thread');
    // Entry point from a listing: opens the thread if it does not exist yet,
    // then redirects to its own URL.
    Route::get('/obiava/{listing}/pisha', ShowThread::class)
        ->middleware('verified')
        ->name('listing.message');

    /*
     * Creating and changing content needs a verified email; reading your own
     * does not. Someone who cannot yet act should still be able to see the
     * state of their own account.
     *
     * 'phone.verified' is still NOT applied. It is the stronger gate and it
     * goes on before launch - the Telegram bot makes it free, so the only
     * reason it is off is that the site is still being built.
     */
    Route::get('/publikuvai', CreateListing::class)
        ->middleware('verified')
        ->name('listing.create');

    /*
     * The same wizard, opened as a copy of one of your own listings.
     *
     * The parameter is named {from} because that is the argument name on
     * CreateListing::mount() - route-model binding matches by name, and a
     * mismatch here fails as "no listing prefilled" rather than as an error,
     * which is the quiet kind.
     *
     * Ownership is checked in mount(), not here: the component has to make the
     * same decision when it is reached any other way.
     */
    Route::get('/publikuvai/kopie/{from}', CreateListing::class)
        ->middleware('verified')
        ->name('listing.duplicate');

    /*
     * The seller's own listings, and editing one.
     *
     * Both are owner-only - EditListing 404s on someone else's listing, and
     * every mutation goes through ListingService, which checks ownership again.
     * Two checks rather than one because the route is the thing most likely to
     * be refactored later.
     */
    Route::get('/moite-obiavi', MyListings::class)->name('listings.mine');
    Route::get('/obiava/{listing}/redakciya', EditListing::class)
        ->middleware('verified')
        ->name('listing.edit');

    /*
     * Bundles. Reading your own list needs nothing beyond being logged in;
     * creating and changing one needs a verified email, same as listings —
     * a bundle publishes seller-written text and a price claim, so it is
     * creating content by any definition that matters.
     *
     * Both management routes point at ONE component. {bundle} is optional in
     * the component's mount() rather than in the route, because two routes
     * with clear names read better in a link than one route with a nullable
     * parameter — and ManageBundle::mount() checks ownership itself, which is
     * the check that survives a refactor of this file.
     */
    Route::get('/moite-komplekti', MyBundles::class)->name('bundles.mine');

    Route::get('/komplekt/nov', ManageBundle::class)
        ->middleware('verified')
        ->name('bundle.create');

    Route::get('/komplekt/{bundle}/redakciya', ManageBundle::class)
        ->middleware('verified')
        ->name('bundle.edit');

    /*
     * Moderation. The middleware answers 404 rather than 403 to anyone who is
     * not a moderator: a 403 confirms the URL is real and worth attacking.
     */
    Route::get('/moderaciya', ModerationQueue::class)
        ->middleware('admin')
        ->name('moderation');

    /*
     * The catalogue's own queue: model names sellers typed because the
     * catalogue did not have them.
     *
     * Admin-gated for the same reason as moderation - it edits other people's
     * listings - but it is curation rather than judgement, which is why it is a
     * separate screen rather than another tab of the moderation queue. Nobody
     * is being told no here.
     */
    Route::get('/katalog', CatalogueQueue::class)
        ->middleware('admin')
        ->name('catalogue');

    Route::post('/izhod', function () {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('browse');
    })->name('logout');
});
