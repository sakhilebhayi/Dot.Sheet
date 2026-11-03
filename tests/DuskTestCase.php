<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Open a page and count Livewire requests from then on, so a test can wait for them to finish.
        Browser::macro('openLivewire', function (string $path) {
            /** @var Browser $this */
            // Ready means Livewire has rendered and every Alpine component on the page has started: until
            // then a click reaches an element whose handler is not attached yet, and nothing happens.
            $this->visit($path)->waitUsing(10, 25, fn () => $this->driver->executeScript(
                "return !! window.Livewire?.initialRenderIsFinished && [...document.querySelectorAll('[x-data]')].every(el => el._x_dataStack)"
            ));
            // Count every Livewire request in flight, from send until its update has been drawn.
            $this->script(<<<'JS'
                window.__livewireBusy = 0;
                window.__livewireQuietSince = performance.now();
                const send = window.fetch;
                window.fetch = (...args) => {
                    if (! String(args[0]).includes('/livewire/')) return send(...args);
                    window.__livewireBusy++;
                    const done = () => requestAnimationFrame(() => setTimeout(() => {
                        if (--window.__livewireBusy === 0) window.__livewireQuietSince = performance.now();
                    }, 0));
                    return send(...args).finally(done);
                };
            JS);

            return $this;
        });

        // Wait until Livewire has been quiet for a moment: no request in flight and the last update drawn.
        // One click here starts a chain (the editor, then the panels it tells), and acting mid-chain clicks
        // a cell that is being redrawn.
        Browser::macro('settle', function () {
            /** @var Browser $this */
            $this->pause(100);

            // Quiet for 250 ms: a chain's next request starts well within that.
            return $this->waitUsing(10, 25, fn () => $this->driver->executeScript(
                'return window.__livewireBusy === 0 && performance.now() - window.__livewireQuietSince > 250'
            ), 'Livewire was still busy');
        });
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            // Web fonts come from Google: refused at once, so a slow network cannot hold a page's load.
            '--host-resolver-rules=MAP fonts.googleapis.com ~NOTFOUND, MAP fonts.gstatic.com ~NOTFOUND',
            // After a real sign-in Chrome offers to save the password, or warns that "password" has leaked;
            // that prompt takes the clicks meant for the page.
            '--disable-features=PasswordLeakDetection,PasswordManagerOnboarding',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        $options->setExperimentalOption('prefs', [
            'credentials_enable_service' => false,
            'profile.password_manager_enabled' => false,
            'profile.password_manager_leak_detection' => false,
        ]);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
                // The tests read the console: a page error fails the journey that caused it.
            )->setCapability('goog:loggingPrefs', ['browser' => 'ALL'])
        );
    }
}
