package fittechfrontdesk;

import javafx.application.Application;
import javafx.fxml.FXMLLoader;
import javafx.scene.Parent;
import javafx.scene.Scene;
import javafx.stage.Stage;

public class FitTechFrontDesk extends Application {

    @Override
    public void start(Stage primaryStage) throws Exception {
    Parent root = FXMLLoader.load(getClass().getResource("resources/Login.fxml"));
    Scene scene = new Scene(root, 500, 500);
    scene.getStylesheets().add(getClass().getResource("resources/style.css").toExternalForm());
    primaryStage.setTitle("FitTech Front Desk");
    
    // Add this line:
    primaryStage.getIcons().add(new javafx.scene.image.Image(
        getClass().getResourceAsStream("resources/favicon.png")));
    
    primaryStage.setScene(scene);
    primaryStage.setResizable(false);
    primaryStage.show();
}

    public static void main(String[] args) {
        launch(args);
    }
}
