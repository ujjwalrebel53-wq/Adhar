package com.rebelb2w.adminpanel;

import android.annotation.SuppressLint;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import android.os.Bundle;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

public class MainActivity extends AppCompatActivity {

    private static final String PREFS = "rebel_b2w_prefs";
    private static final String KEY_URL = "server_url";
    private static final int FILE_CHOOSER_REQUEST = 1001;

    private WebView webView;
    private ProgressBar progressBar;
    private SwipeRefreshLayout swipeRefresh;
    private String serverUrl;
    private ValueCallback<Uri[]> filePathCallback;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        serverUrl = getIntent().getStringExtra("url");
        if (serverUrl == null || serverUrl.isEmpty()) {
            SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
            serverUrl = prefs.getString(KEY_URL, "");
        }

        if (getSupportActionBar() != null) {
            getSupportActionBar().setTitle("💰 Rebel B2W Admin");
            getSupportActionBar().setSubtitle("Bot Control Panel");
        }

        webView = findViewById(R.id.webview);
        progressBar = findViewById(R.id.progress_bar);
        swipeRefresh = findViewById(R.id.swipe_refresh);

        setupWebView();
        setupSwipeRefresh();

        if (!serverUrl.isEmpty()) {
            webView.loadUrl(serverUrl);
        } else {
            Toast.makeText(this, "Server URL not set!", Toast.LENGTH_LONG).show();
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setBuiltInZoomControls(false);
        settings.setDisplayZoomControls(false);
        settings.setSupportZoom(false);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(true);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setMediaPlaybackRequiresUserGesture(false);

        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true);

        webView.addJavascriptInterface(new AndroidBridge(), "Android");

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();
                // Open external links in browser
                if (!url.contains(getBaseHost(serverUrl))) {
                    try {
                        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                    } catch (Exception e) {
                        view.loadUrl(url);
                    }
                    return true;
                }
                return false;
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                progressBar.setVisibility(View.VISIBLE);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                progressBar.setVisibility(View.GONE);
                swipeRefresh.setRefreshing(false);
                // Inject mobile-friendly CSS tweaks
                injectMobileCSS();
            }

            @Override
            public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
                progressBar.setVisibility(View.GONE);
                swipeRefresh.setRefreshing(false);
                showOfflinePage();
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
            }

            @Override
            public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> filePathCallback,
                                              FileChooserParams fileChooserParams) {
                MainActivity.this.filePathCallback = filePathCallback;
                Intent intent = fileChooserParams.createIntent();
                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST);
                } catch (Exception e) {
                    MainActivity.this.filePathCallback = null;
                    return false;
                }
                return true;
            }

            @Override
            public void onReceivedTitle(WebView view, String title) {
                if (getSupportActionBar() != null && title != null && !title.isEmpty()) {
                    getSupportActionBar().setTitle(title);
                }
            }
        });
    }

    private void setupSwipeRefresh() {
        swipeRefresh.setColorSchemeColors(
                0xFFFF6B1A, 0xFF39FF14, 0xFF7C7CFF
        );
        swipeRefresh.setOnRefreshListener(() -> webView.reload());
    }

    private void injectMobileCSS() {
        String css = "var s=document.createElement('style');" +
                "s.textContent='" +
                "body{-webkit-text-size-adjust:100%!important}" +
                ".wrap{padding:12px 10px!important}" +
                "input,select,textarea{font-size:16px!important}" +
                "button.btn{min-height:40px!important;padding:8px 12px!important}" +
                ".card{padding:14px!important}" +
                "';" +
                "document.head.appendChild(s);";
        webView.evaluateJavascript("(function(){" + css + "})()", null);
    }

    private void showOfflinePage() {
        String html = "<html><body style='background:#0e0e12;color:#e8e8f0;font-family:sans-serif;" +
                "display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;padding:20px'>" +
                "<div style='font-size:48px'>📡</div>" +
                "<h2 style='color:#ff4466;margin:12px 0'>Connection Error</h2>" +
                "<p style='color:#8888aa;font-size:14px'>Server se connect nahi ho pa raha.<br>Check karein ki server URL sahi hai aur server chal raha hai.</p>" +
                "<p style='color:#555577;font-size:12px;margin-top:8px'>URL: " + serverUrl + "</p>" +
                "<button onclick='location.reload()' style='margin-top:20px;padding:10px 24px;background:#7c7cff;color:#000;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer'>🔄 Retry</button>" +
                "<button onclick='Android.changeUrl()' style='margin-top:10px;padding:10px 24px;background:#2a2a3a;color:#e8e8f0;border:1px solid #2a2a3a;border-radius:8px;font-size:14px;cursor:pointer'>⚙️ Change URL</button>" +
                "</body></html>";
        webView.loadDataWithBaseURL(null, html, "text/html", "UTF-8", null);
    }

    private String getBaseHost(String url) {
        try {
            Uri uri = Uri.parse(url);
            return uri.getHost() != null ? uri.getHost() : url;
        } catch (Exception e) {
            return url;
        }
    }

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, 1, 0, "🔄 Refresh").setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        menu.add(0, 2, 0, "⚙️ Change URL").setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        menu.add(0, 3, 0, "🏠 Home").setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        menu.add(0, 4, 0, "ℹ️ About").setShowAsAction(MenuItem.SHOW_AS_ACTION_NEVER);
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        switch (item.getItemId()) {
            case 1: webView.reload(); return true;
            case 2: showChangeUrlDialog(); return true;
            case 3: webView.loadUrl(serverUrl); return true;
            case 4: showAbout(); return true;
        }
        return super.onOptionsItemSelected(item);
    }

    private void showChangeUrlDialog() {
        android.widget.EditText input = new android.widget.EditText(this);
        input.setHint("https://yourserver.com/rb_deposit_bot.php");
        input.setText(serverUrl);
        input.setTextColor(0xFFE8E8F0);
        input.setHintTextColor(0xFF555577);
        input.setBackgroundTintList(android.content.res.ColorStateList.valueOf(0xFF7C7CFF));
        input.setPadding(32, 24, 32, 24);

        new AlertDialog.Builder(this)
                .setTitle("⚙️ Server URL Change Karo")
                .setMessage("Apne PHP server ka URL enter karo:")
                .setView(input)
                .setPositiveButton("Save & Open", (d, w) -> {
                    String newUrl = input.getText().toString().trim();
                    if (!newUrl.isEmpty()) {
                        if (!newUrl.startsWith("http")) newUrl = "http://" + newUrl;
                        serverUrl = newUrl;
                        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
                        prefs.edit().putString(KEY_URL, newUrl).apply();
                        webView.loadUrl(serverUrl);
                        Toast.makeText(this, "URL saved!", Toast.LENGTH_SHORT).show();
                    }
                })
                .setNegativeButton("Cancel", null)
                .show();
    }

    private void showAbout() {
        new AlertDialog.Builder(this)
                .setTitle("💰 Rebel B2W Admin")
                .setMessage("Version: 1.0\n\nYeh app aapke Rebel B2W Deposit Bot ke admin panel ko control karne ke liye hai.\n\nServer: " + serverUrl)
                .setPositiveButton("OK", null)
                .show();
    }

    @Override
    public void onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            new AlertDialog.Builder(this)
                    .setTitle("Exit")
                    .setMessage("App band karna chahte ho?")
                    .setPositiveButton("Haan", (d, w) -> finish())
                    .setNegativeButton("Nahi", null)
                    .show();
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == FILE_CHOOSER_REQUEST) {
            if (filePathCallback != null) {
                Uri[] results = WebChromeClient.FileChooserParams.parseResult(resultCode, data);
                filePathCallback.onReceiveValue(results);
                filePathCallback = null;
            }
        }
    }

    public class AndroidBridge {
        @JavascriptInterface
        public void changeUrl() {
            runOnUiThread(() -> showChangeUrlDialog());
        }

        @JavascriptInterface
        public void showToast(String msg) {
            runOnUiThread(() -> Toast.makeText(MainActivity.this, msg, Toast.LENGTH_SHORT).show());
        }
    }
}
