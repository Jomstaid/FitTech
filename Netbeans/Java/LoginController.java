package fittechfrontdesk.controller;

import com.google.gson.JsonObject;
import fittechfrontdesk.model.SessionManager;
import fittechfrontdesk.service.ApiService;
import javafx.application.Platform;
import javafx.fxml.FXML;
import javafx.fxml.FXMLLoader;
import javafx.scene.Parent;
import javafx.scene.Scene;
import javafx.scene.control.*;
import javafx.stage.Stage;

public class LoginController {

    @FXML private TextField     usernameField;
    @FXML private PasswordField passwordField;
    @FXML private Label         errorLabel;
    @FXML private Button        loginButton;

    @FXML
    private void handleLogin() {
        String username = usernameField.getText().trim();
        String password = passwordField.getText().trim();

        if (username.isEmpty() || password.isEmpty()) {
            errorLabel.setText("Please enter username and password.");
            return;
        }

        loginButton.setDisable(true);
        errorLabel.setText("Logging in...");

        // Run on background thread so UI doesn't freeze
        new Thread(() -> {
            try {
                JsonObject result = ApiService.login(username, password);

                Platform.runLater(() -> {
                    loginButton.setDisable(false);

                    if (result.has("success") && result.get("success").getAsBoolean()) {
                        JsonObject user = result.getAsJsonObject("user");

                        boolean isAdmin = user.has("is_admin") && user.get("is_admin").getAsBoolean();
                        if (!isAdmin) {
                            errorLabel.setText("Access denied. Admin accounts only.");
                            return;
                        }

                        // Save session
                        SessionManager.getInstance().setAdmin(
                            user.get("id").getAsInt(),
                            user.get("full_name").getAsString(),
                            user.get("username").getAsString()
                        );

                        openDashboard();

                    } else {
                        String err = result.has("error") ? result.get("error").getAsString() : "Login failed.";
                        errorLabel.setText(err);
                    }
                });

            } catch (Exception e) {
                Platform.runLater(() -> {
                    loginButton.setDisable(false);
                    errorLabel.setText("Cannot connect to server. Is XAMPP running?");
                });
            }
        }).start();
    }

    // Bug 5 fix: use new FXMLLoader(url).load() so the controller reference is
    // available, then wire stage.setOnCloseRequest to call ctrl.shutdown() so
    // background threads are stopped cleanly when the window closes.
    // Also bumped initial window to 1100×700 with min-size guards.
    private void openDashboard() {
        try {
            java.net.URL fxmlUrl = getClass().getResource("/fittechfrontdesk/resources/Dashboard.fxml");
            FXMLLoader loader = new FXMLLoader(fxmlUrl);
            Parent root = loader.load();
            DashboardController ctrl = loader.getController();

            Stage stage = (Stage) loginButton.getScene().getWindow();
            Scene scene = new Scene(root, 1100, 700);
            scene.getStylesheets().add(
                getClass().getResource("/fittechfrontdesk/resources/style.css").toExternalForm());
            stage.setScene(scene);
            stage.setTitle("FitTech Front Desk — Dashboard");
            stage.setResizable(true);
            stage.setMinWidth(900);
            stage.setMinHeight(600);
            stage.centerOnScreen();

            // Shut down background scheduler when window is closed
            stage.setOnCloseRequest(e -> ctrl.shutdown());

        } catch (Exception e) {
            errorLabel.setText("Error loading dashboard: " + e.getMessage());
        }
    }
}
