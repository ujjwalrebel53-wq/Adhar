package com.rebelb2w.adminpanel;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

public class SetupActivity extends AppCompatActivity {

    private static final String PREFS = "rebel_b2w_prefs";
    private static final String KEY_URL = "server_url";

    private EditText etUrl;
    private Button btnGo;
    private TextView tvError;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // If URL already saved, go directly to main
        SharedPreferences prefs = getSharedPreferences(PREFS, MODE_PRIVATE);
        String savedUrl = prefs.getString(KEY_URL, "");
        if (!TextUtils.isEmpty(savedUrl)) {
            launchMain(savedUrl);
            return;
        }

        setContentView(R.layout.activity_setup);
        etUrl = findViewById(R.id.et_url);
        btnGo = findViewById(R.id.btn_go);
        tvError = findViewById(R.id.tv_error);

        btnGo.setOnClickListener(v -> {
            String url = etUrl.getText().toString().trim();
            if (TextUtils.isEmpty(url)) {
                tvError.setText("URL required hai");
                tvError.setVisibility(View.VISIBLE);
                return;
            }
            if (!url.startsWith("http://") && !url.startsWith("https://")) {
                url = "http://" + url;
            }
            if (!url.contains("rb_deposit_bot.php") && !url.endsWith("/")) {
                if (!url.contains(".php")) {
                    url = url + "/rb_deposit_bot.php";
                }
            }
            prefs.edit().putString(KEY_URL, url).apply();
            launchMain(url);
        });
    }

    private void launchMain(String url) {
        Intent intent = new Intent(this, MainActivity.class);
        intent.putExtra("url", url);
        startActivity(intent);
        finish();
    }
}
