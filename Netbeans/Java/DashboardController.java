package fittechfrontdesk.controller;

import com.google.gson.*;
import fittechfrontdesk.model.SessionManager;
import fittechfrontdesk.service.ApiService;
import javafx.application.Platform;
import javafx.collections.*;
import javafx.fxml.FXML;
import javafx.fxml.Initializable;
import javafx.scene.control.*;
import javafx.scene.control.Alert;
import javafx.scene.control.ButtonType;
import javafx.beans.property.SimpleStringProperty;
import javafx.scene.input.MouseEvent;
import javafx.scene.layout.VBox;

import java.net.URL;
import java.time.*;
import java.time.format.DateTimeFormatter;
import java.time.temporal.ChronoUnit;
import java.util.*;
import java.util.concurrent.*;
import java.util.regex.Pattern;

public class DashboardController implements Initializable {

    // ── TOP BAR ──
    @FXML private Label welcomeLabel;
    @FXML private Label clockLabel;

    // ── STATS BAR ──
    @FXML private Label statCheckinsToday;
    @FXML private Label statActiveNow;
    @FXML private Label statWalkinsToday;
    @FXML private Label statTotalMembers;

    // ── CHECK-IN TAB ──
    @FXML private TextField searchField;
    @FXML private TableView<JsonObject> memberTable;
    @FXML private TableColumn<JsonObject, String> colId, colName, colUsername,
                                                   colMembership, colStatus, colExpiry, colDaysLeft;
    @FXML private Label checkInStatus;
    @FXML private Label recentCheckins;

    // ── REGISTER TAB ──
    @FXML private TextField    regFullName, regUsername, regEmail, regPhone, regAge;
    @FXML private PasswordField regPassword;
    @FXML private DatePicker   regBirthdate;
    @FXML private ComboBox<String> regGender, regMembership;
    @FXML private Label regStatus, membershipPreview;
    @FXML private Label errFullName, errUsername, errEmail, errPhone, errBirthdate, errPassword, errGender;
    @FXML private Label pwStrength1, pwStrength2, pwStrength3, pwStrengthLabel;
    @FXML private Button regButton;

    // ── SCHEDULE TAB ──
    @FXML private TableView<JsonObject> scheduleTable;
    @FXML private TableColumn<JsonObject, String> schMember, schTrainer, schDate,
                                                   schTime, schEndTime, schType,
                                                   schStatus, schCountdown, schAmount;
    @FXML private Label scheduleStatus;
    @FXML private Label liveSessionCount;

    // ── PENDING ORDERS TAB ──
    @FXML private TableView<JsonObject> cartTable;
    @FXML private TableColumn<JsonObject, String> cartColOrderId, cartColName, cartColUsername,
                                                   cartColMembership, cartColItems, cartColValue,
                                                   cartColDetail, cartColDate;
    @FXML private Button cartApproveBtn;
    @FXML private Button cartRejectBtn;
    @FXML private Label cartStatus;
    @FXML private Label cartSummaryLabel;

    // ── PURCHASE ANALYTICS TAB ──
    @FXML private TableView<JsonObject> purchaseTable;
    @FXML private TableColumn<JsonObject, String> purColName, purColUsername, purColMembership,
                                                   purColOrders, purColItems, purColSpent,
                                                   purColFirst, purColLast, purColDetail;
    @FXML private Label purchaseStatus;
    @FXML private Label purchaseSummaryLabel;

    // ── MEMBERS TAB ──
    @FXML private TextField membersSearchField;
    @FXML private Label membersTabCount;
    @FXML private Label membersTabStatus;
    @FXML private TableView<JsonObject> membersTable;
    @FXML private TableColumn<JsonObject, String>
            mColId, mColName, mColUsername, mColEmail, mColPhone,
            mColGender, mColBirthdate, mColAge, mColMembership,
            mColStatus, mColExpiry, mColDaysLeft;

    // ── RENEWAL TAB ──
    @FXML private TableView<JsonObject> renewalTable;
    @FXML private TableColumn<JsonObject, String>
            renColId, renColName, renColUsername, renColMembership,
            renColStatus, renColExpiry, renColDaysLeft, renColPhone, renColEmail;
    @FXML private ComboBox<String> renewMembershipCombo;
    @FXML private Label renewalSummaryLabel;
    @FXML private Label renewalStatus;

    // ── EQUIPMENT TAB ──
    @FXML private TableView<JsonObject> equipTable;
    @FXML private TableColumn<JsonObject, String>
            equipColId, equipColName, equipColCategory, equipColCondition,
            equipColQty, equipColLocation, equipColLastCheck, equipColNotes;
    @FXML private VBox             equipEditPanel;
    @FXML private Label            equipEditName;       // repurposed as panel title label
    @FXML private TextField        equipNameField, equipQtyField, equipLocationField, equipNotesField, equipSearchField;
    @FXML private ComboBox<String> equipCategoryCombo, equipConditionCombo;
    @FXML private Label            equipErrName, equipErrCategory, equipErrQty;
    @FXML private Button           equipSaveBtn, equipDeleteBtn;
    @FXML private Label            equipStatusLabel;   // inside panel
    @FXML private Label            equipStatusFooter;  // footer bar
    @FXML private Label            equipSummaryLabel;

    // ── PASSWORD RESET TAB ──
    @FXML private TextField     pwrEmailField;
    @FXML private Button        pwrGenerateBtn, pwrResetBtn;
    @FXML private Label         pwrStep1Status, pwrTokenDisplay, pwrMemberName, pwrPasswordErr, pwrResetStatus;
    @FXML private VBox          pwrStep2Box, pwrStep3Box;
    @FXML private PasswordField pwrNewPassword, pwrConfirmPassword;

    // ── ANALYTICS TAB ──
    @FXML private Label anTotalMembers, anActiveMembers, anExpiredMembers,
                        anExpiringSoon, anTodayCheckins, anRevenue;
    @FXML private TableView<JsonObject> anBreakdownTable;
    @FXML private TableColumn<JsonObject, String>
            anBrkType, anBrkCount, anBrkActive, anBrkExpired, anBrkRevenue;
    @FXML private TableView<JsonObject> anCheckinsTable;
    @FXML private TableColumn<JsonObject, String>
            anChkName, anChkMembership, anChkType, anChkTime;
    @FXML private Label analyticsStatus;

    // ── EDIT PANEL (inside Members tab) ──
    @FXML private VBox    memberEditPanel;
    @FXML private Label   editMemberId;
    @FXML private TextField editFullName, editUsername, editEmail, editPhone;
    @FXML private ComboBox<String> editGender, editMembership, editStatus; // ComboBox for status selection
    @FXML private DatePicker editBirthdate, editExpiry;
    @FXML private Label   editErrFullName, editErrUsername, editErrEmail,
                          editErrPhone, editErrGender, editErrBirthdate;
    @FXML private Label   editStatusLabel;  // FIX: renamed from editStatus to avoid clash with the ComboBox above
    @FXML private Button  editSaveBtn;

    // ── DATA ──
    private final ObservableList<JsonObject> memberData      = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> allMembers      = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> scheduleData    = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> cartData        = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> purchaseData    = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> allMembersFull  = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> membersFiltered = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> renewalData     = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> renewalAll      = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> equipData       = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> anBreakdownData = FXCollections.observableArrayList();
    private final ObservableList<JsonObject> anCheckinsData  = FXCollections.observableArrayList();
    private final List<String> recentCheckInNames            = new ArrayList<>();
    private JsonObject currentlyEditing      = null;
    private JsonObject currentlyEditingEquip = null;
    private boolean    equipIsAdding         = false;  // true = Add mode, false = Edit mode
    private String     pwrCurrentToken       = null;   // token returned from server for current reset session

    // ── SCHEDULERS ──
    private ScheduledExecutorService scheduler;

    // ── VALIDATION PATTERNS (match website exactly) ──
    private static final Pattern PHONE_PATTERN = Pattern.compile(
    "^(0(904|905|906|915|916|917|925|926|927|935|936|937|945|953|954|955|956|965|966|967|975|976|977|978|979|985|986|987|907|908|909|910|911|912|913|914|918|919|920|921|922|923|924|928|929|930|931|932|933|934|938|939|946|947|948|949|950|951|952|960|961|963|968|969|970|981|989|998|999|895|896|897|991|992|993|994)\\d{7}|\\+639(04|05|06|15|16|17|25|26|27|35|36|37|45|53|54|55|56|65|66|67|75|76|77|78|79|85|86|87|07|08|09|10|11|12|13|14|18|19|20|21|22|23|24|28|29|30|31|32|33|34|38|39|46|47|48|49|50|51|52|60|61|63|68|69|70|81|89|98|99|95|96|97|91|92|93|94)\\d{7})$"
);
    private static final Pattern GMAIL_PATTERN    = Pattern.compile("^[a-zA-Z0-9._%+\\-]+@gmail\\.com$", Pattern.CASE_INSENSITIVE);
    private static final Pattern USERNAME_PATTERN = Pattern.compile("^[a-zA-Z0-9_]{3,20}$");

    @Override
    public void initialize(URL url, ResourceBundle rb) {
        String name = SessionManager.getInstance().getAdminName();
        welcomeLabel.setText("Admin: " + name);

        setupMemberTable();
        setupScheduleTable();
        setupCartTable();
        setupPurchaseTable();
        setupMembersTab();
        setupRegisterForm();
        setupLiveValidation();
        setupRenewalTab();
        setupEquipmentTab();
        setupAnalyticsTab();
        setupPasswordResetTab();

        loadAllMembers();
        loadStats();          // FIX: was missing in the broken version — populates check-in/walk-in counters on startup
        loadTodaySchedule();
        loadCartAnalytics();
        loadPurchaseAnalytics();
        loadMembersFull();
        loadRenewalData();
        loadEquipment();
        loadAnalytics();
        startLiveTimers();
    }

    // ══════════════════════════════════════════════
    //  LIVE TIMERS (clock, countdown, auto-refresh)
    // ══════════════════════════════════════════════

    private void startLiveTimers() {
        scheduler = Executors.newScheduledThreadPool(2,
            r -> { Thread t = new Thread(r); t.setDaemon(true); return t; });

        // 1. Clock — every second
        scheduler.scheduleAtFixedRate(() -> {
            String time = LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm:ss a"));
            String date = LocalDate.now().format(DateTimeFormatter.ofPattern("EEE, MMM d yyyy"));
            Platform.runLater(() -> clockLabel.setText(date + "  " + time));
        }, 0, 1, TimeUnit.SECONDS);

        // 2. Countdown column + active session badge — every second
        scheduler.scheduleAtFixedRate(() -> {
            Platform.runLater(() -> {
                scheduleTable.refresh();
                updateActiveCount();
            });
        }, 1, 1, TimeUnit.SECONDS);

        // 3. Auto-refresh all data — every 60 seconds
        scheduler.scheduleAtFixedRate(() -> {
            loadTodaySchedule();
            loadStats();
            loadCartAnalytics();
            loadPurchaseAnalytics();
            loadRenewalData();
            loadAnalytics();
        }, 60, 60, TimeUnit.SECONDS);
    }

    private void updateActiveCount() {
        long active = scheduleData.stream()
                .filter(b -> "ACTIVE".equals(sessionState(b))).count();
        liveSessionCount.setText(active + " active");
        liveSessionCount.setStyle(active > 0
            ? "-fx-text-fill: #7cff6b; -fx-font-size: 12; -fx-font-weight: bold;" +
              "-fx-background-color: rgba(124,255,107,0.12); -fx-padding: 4 10; -fx-background-radius: 12;"
            : "-fx-text-fill: #555; -fx-font-size: 12;" +
              "-fx-background-color: transparent; -fx-padding: 4 10;");
        statActiveNow.setText(String.valueOf(active));
    }

    // ══════════════════════════════════════════════
    //  CHECK-IN TAB
    // ══════════════════════════════════════════════

    private void setupMemberTable() {
        colId.setCellValueFactory(r         -> str(r, "id"));
        colName.setCellValueFactory(r       -> str(r, "full_name"));
        colUsername.setCellValueFactory(r   -> str(r, "username"));
        colMembership.setCellValueFactory(r -> str(r, "membership_type"));
        colStatus.setCellValueFactory(r     -> str(r, "status"));
        colExpiry.setCellValueFactory(r     -> new SimpleStringProperty(
                formatDate(safeStr(r.getValue(), "expiry_date"))));

        // Days-left column with colour coding
        colDaysLeft.setCellValueFactory(r -> {
            String exp = safeStr(r.getValue(), "expiry_date");
            if (exp.isEmpty()) return new SimpleStringProperty("—");
            try {
                LocalDate expDate = LocalDate.parse(exp);
                long days = ChronoUnit.DAYS.between(LocalDate.now(), expDate);
                if (days < 0)  return new SimpleStringProperty("EXPIRED");
                if (days == 0) return new SimpleStringProperty("Today!");
                return new SimpleStringProperty(days + "d");
            } catch (Exception e) {
                return new SimpleStringProperty("—");
            }
        });

        // Row colour coding by status / expiry
        memberTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override
            protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                String status = safeStr(item, "status");
                long days = Long.MAX_VALUE;
                try {
                    String exp = safeStr(item, "expiry_date");
                    if (!exp.isEmpty())
                        days = ChronoUnit.DAYS.between(LocalDate.now(), LocalDate.parse(exp));
                } catch (Exception ignored) {}

                if ("Expired".equals(status) || days < 0) setStyle("-fx-background-color: #2a1010;");
                else if (days <= 7)                        setStyle("-fx-background-color: #2a2000;");
                else                                       setStyle("");
            }
        });

        memberTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        memberTable.setItems(memberData);
    }

    @FXML
    private void handleSearchLive() { filterMembers(searchField.getText().trim()); }

    @FXML
    private void handleSearch() { filterMembers(searchField.getText().trim()); }

    @FXML
    private void handleShowAll() {
        searchField.clear();
        memberData.setAll(allMembers);
        checkInStatus.setText("Showing all " + memberData.size() + " members.");
    }

    @FXML
    private void handleRefreshMembers() {
        checkInStatus.setText("Refreshing...");
        loadAllMembers();
    }

    private void filterMembers(String query) {
        if (query.isEmpty()) {
            memberData.setAll(allMembers);
            checkInStatus.setText("Showing all " + memberData.size() + " members.");
            return;
        }
        String q = query.toLowerCase();
        ObservableList<JsonObject> filtered = FXCollections.observableArrayList();
        for (JsonObject m : allMembers) {
            // compute days-left string for searching
            String daysStr = "";
            String exp = safeStr(m, "expiry_date");
            if (!exp.isEmpty()) {
                try {
                    long days = ChronoUnit.DAYS.between(LocalDate.now(), LocalDate.parse(exp));
                    daysStr = days < 0 ? "expired" : days == 0 ? "today" : days + "d";
                } catch (Exception ignored) {}
            }
            // compute age string
            String ageStr = "";
            String bd = safeStr(m, "birthdate");
            if (!bd.isEmpty()) {
                try { ageStr = String.valueOf(Period.between(LocalDate.parse(bd), LocalDate.now()).getYears()); }
                catch (Exception ignored) {}
            }
            if (safeStr(m, "full_name").toLowerCase().contains(q)       ||
                safeStr(m, "username").toLowerCase().contains(q)         ||
                safeStr(m, "email").toLowerCase().contains(q)            ||
                safeStr(m, "phone").toLowerCase().contains(q)            ||
                safeStr(m, "membership_type").toLowerCase().contains(q)  ||
                safeStr(m, "status").toLowerCase().contains(q)           ||
                safeStr(m, "gender").toLowerCase().contains(q)           ||
                formatDate(exp).toLowerCase().contains(q)                ||
                daysStr.contains(q)                                      ||
                ageStr.contains(q)                                       ||
                safeStr(m, "id").contains(q)) {
                filtered.add(m);
            }
        }
        memberData.setAll(filtered);
        checkInStatus.setText("Found " + filtered.size() + " result(s) for \"" + query + "\"");
    }

    @FXML
    private void handleCheckIn() {
        JsonObject selected = memberTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            setStatus(checkInStatus, "⚠ Select a member from the table first.", "warn");
            return;
        }
        if ("Expired".equalsIgnoreCase(safeStr(selected, "status"))) {
            setStatus(checkInStatus, "✗ Cannot check in: membership is EXPIRED. Please renew first.", "error");
            return;
        }
        int    memberId   = selected.get("id").getAsInt();
        String memberName = safeStr(selected, "full_name");
        String membership = safeStr(selected, "membership_type");

        new Thread(() -> {
            try {
                JsonObject result = ApiService.checkin(memberId);
                Platform.runLater(() -> {
                    if (result.has("success")) {
                        setStatus(checkInStatus,
                            "✓ " + memberName + " (" + membership + ") checked in at " +
                            LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")), "ok");
                        addRecentCheckin(memberName);
                        loadStats();
                    } else {
                        setStatus(checkInStatus, "✗ " + safeStr(result, "error"), "error");
                    }
                });
            } catch (Exception e) {
                Platform.runLater(() ->
                    setStatus(checkInStatus, "✗ Connection error: " + e.getMessage(), "error"));
            }
        }).start();
    }

    @FXML
    private void handleWalkin() {
        TextInputDialog dlg = new TextInputDialog("Walk-in Guest");
        dlg.setTitle("Log Walk-in");
        dlg.setHeaderText("Walk-in Visitor — ₱300");
        dlg.setContentText("Visitor name (optional):");
        styleDialog(dlg);
        dlg.showAndWait().ifPresent(name -> {
            String visitorName = name.trim().isEmpty() ? "Walk-in Guest" : name.trim();
            new Thread(() -> {
                try {
                    JsonObject result = ApiService.logWalkin(visitorName, 300);
                    Platform.runLater(() -> {
                        if (result.has("success")) {
                            setStatus(checkInStatus,
                                "🚶 Walk-in logged: " + visitorName + " — ₱300 at " +
                                LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")), "ok");
                            addRecentCheckin("🚶 " + visitorName);
                            loadStats();
                        } else {
                            setStatus(checkInStatus, "✗ " + safeStr(result, "error"), "error");
                        }
                    });
                } catch (Exception e) {
                    Platform.runLater(() ->
                        setStatus(checkInStatus, "✗ Error: " + e.getMessage(), "error"));
                }
            }).start();
        });
    }

    private void addRecentCheckin(String name) {
        recentCheckins.setText("");
        recentCheckInNames.add(0, name);
        if (recentCheckInNames.size() > 5) recentCheckInNames.remove(recentCheckInNames.size() - 1);
        recentCheckins.setText(String.join("  ·  ", recentCheckInNames));
    }

    private void loadAllMembers() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonArray members = ApiService.getAllMembers(adminId);
                Platform.runLater(() -> {
                    allMembers.clear();
                    members.forEach(m -> allMembers.add(m.getAsJsonObject()));
                    memberData.setAll(allMembers);
                    checkInStatus.setText("Loaded " + allMembers.size() + " members.");
                    statTotalMembers.setText(String.valueOf(allMembers.size()));
                });
            } catch (Exception e) {
                Platform.runLater(() ->
                    setStatus(checkInStatus, "✗ Failed to load members: " + e.getMessage(), "error"));
            }
        }).start();
    }

    private void loadStats() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonObject ov = ApiService.getOverview(adminId);
                Platform.runLater(() -> {
                    statCheckinsToday.setText(safeStr(ov, "today_checkins"));
                    statWalkinsToday.setText(safeStr(ov, "today_walkins"));
                });
            } catch (Exception ignored) {}
        }).start();
    }

    // ══════════════════════════════════════════════
    //  REGISTER TAB
    // ══════════════════════════════════════════════

    private void setupRegisterForm() {
        regGender.setItems(FXCollections.observableArrayList("Male", "Female"));
        regMembership.setItems(FXCollections.observableArrayList("Basic", "Premium", "VIP"));
        regGender.setPromptText("Select sex");
        regMembership.setValue("Basic");
        regMembership.setOnAction(e -> updateMembershipPreview());
        updateMembershipPreview();
    }

    private void updateMembershipPreview() {
        String m = regMembership.getValue();
        if (m == null) return;
        switch (m) {
            case "Basic"   -> membershipPreview.setText("Basic — ₱2,500 / 1 month");
            case "Premium" -> membershipPreview.setText("Premium — ₱12,000 / 6 months  (Best Value)");
            case "VIP"     -> membershipPreview.setText("VIP — ₱20,000 / 1 year  (All Amenities)");
        }
    }

    private void setupLiveValidation() {
        // Auto-calculate age from DatePicker
        regBirthdate.setOnAction(e -> {
            if (regBirthdate.getValue() != null) {
                LocalDate bd    = regBirthdate.getValue();
                LocalDate today = LocalDate.now();
                if (!bd.isBefore(today)) {
                    regAge.setText("Invalid");
                    errBirthdate.setText("Birthdate cannot be today or in the future.");
                } else {
                    int age = Period.between(bd, today).getYears();
                    regAge.setText(age + " years old");
                    validateBirthdate();
                }
            }
        });

        // Live password strength meter
        regPassword.textProperty().addListener((obs, oldVal, newVal) -> {
            updatePasswordStrength(newVal);
            if (!newVal.isEmpty()) validatePassword();
        });

        // Phone — digits and + only, strip everything else automatically
        regPhone.textProperty().addListener((obs, oldVal, newVal) -> {
            String clean = newVal.replaceAll("[^0-9+]", "");
            if (!clean.equals(newVal)) regPhone.setText(clean);
        });

        // Username — letters, numbers, underscore only
        regUsername.textProperty().addListener((obs, oldVal, newVal) -> {
            String clean = newVal.replaceAll("[^a-zA-Z0-9_]", "");
            if (!clean.equals(newVal)) regUsername.setText(clean);
        });
    }

    private void updatePasswordStrength(String pw) {
        int score = 0;
        if (pw.length() >= 8)                score++;
        if (pw.matches(".*[A-Z].*"))          score++;
        if (pw.matches(".*[0-9].*"))          score++;
        if (pw.matches(".*[^a-zA-Z0-9].*"))   score++;
        if (pw.length() >= 12)                score++;

        String[] colors = {"#222", "#ff5555", "#ff8c00", "#ffd700", "#7cff6b"};
        String[] labels = {"", "Weak", "Fair", "Good", "Strong"};
        int level = Math.min(score, 4);

        pwStrength1.setStyle("-fx-background-color: " + (level >= 1 ? colors[level] : "#222") +
            "; -fx-background-radius: 3; -fx-min-height: 6; -fx-max-height: 6;");
        pwStrength2.setStyle("-fx-background-color: " + (level >= 2 ? colors[level] : "#222") +
            "; -fx-background-radius: 3; -fx-min-height: 6; -fx-max-height: 6;");
        pwStrength3.setStyle("-fx-background-color: " + (level >= 3 ? colors[level] : "#222") +
            "; -fx-background-radius: 3; -fx-min-height: 6; -fx-max-height: 6;");
        pwStrengthLabel.setText(pw.isEmpty() ? "" : labels[level]);
        pwStrengthLabel.setStyle("-fx-text-fill: " + colors[level] + "; -fx-font-size: 10; -fx-padding: 0 0 0 8;");
    }

    // ── Individual field validators — return true if valid ──

    private boolean validateFullName() {
        String v = regFullName.getText().trim();
        if (v.length() < 2) {
            errFullName.setText("Min 2 characters.");
            highlightField(regFullName, false); return false;
        }
        if (v.length() > 100) {
            errFullName.setText("Max 100 characters.");
            highlightField(regFullName, false); return false;
        }
        if (!v.matches("[a-zA-ZÀ-ÿ\\s\\-\\.]+")) {
            errFullName.setText("Letters, spaces, hyphens and periods only.");
            highlightField(regFullName, false); return false;
        }
        errFullName.setText(""); highlightField(regFullName, true); return true;
    }

    private boolean validateUsername() {
        String v = regUsername.getText().trim();
        if (!USERNAME_PATTERN.matcher(v).matches()) {
            errUsername.setText("3–20 chars, letters/numbers/underscores only.");
            highlightField(regUsername, false); return false;
        }
        errUsername.setText(""); highlightField(regUsername, true); return true;
    }

    private boolean validateEmail() {
        String v = regEmail.getText().trim();
        if (!GMAIL_PATTERN.matcher(v).matches()) {
            errEmail.setText("Must be a valid Gmail address (e.g. name@gmail.com).");
            highlightField(regEmail, false); return false;
        }
        errEmail.setText(""); highlightField(regEmail, true); return true;
    }

    private boolean validatePhone() {
    String v = regPhone.getText().trim().replaceAll("[\\s\\-]", "");
    if (!PHONE_PATTERN.matcher(v).matches()) {
        errPhone.setText("Invalid Philippine number. Use 09XXXXXXXXX or +639XXXXXXXXX with a valid network prefix.");
        highlightField(regPhone, false); return false;
    }
    errPhone.setText(""); highlightField(regPhone, true); return true;
}

    private boolean validatePassword() {
        String v = regPassword.getText();
        if (v.length() < 8) {
            errPassword.setText("Minimum 8 characters required."); return false;
        }
        if (!v.matches(".*[A-Za-z].*") || !v.matches(".*[0-9\\W].*")) {
            errPassword.setText("Must contain at least one letter and one number or special character.");
            return false;
        }
        errPassword.setText(""); return true;
    }

    private boolean validateBirthdate() {
        if (regBirthdate.getValue() == null) {
            errBirthdate.setText("Birthdate is required."); return false;
        }
        LocalDate bd    = regBirthdate.getValue();
        LocalDate today = LocalDate.now();
        if (!bd.isBefore(today)) {
            errBirthdate.setText("Birthdate cannot be today or in the future."); return false;
        }
        int age = Period.between(bd, today).getYears();
        if (age < 15 || age > 80) {
            errBirthdate.setText("Age must be between 15 and 80 years old."); return false;
        }
        errBirthdate.setText(""); return true;
    }

    private boolean validateGender() {
        if (regGender.getValue() == null || regGender.getValue().isEmpty()) {
            errGender.setText("Please select a sex."); return false;
        }
        errGender.setText(""); return true;
    }

    private void highlightField(TextField field, boolean ok) {
        field.setStyle(field.getStyle().replaceAll("-fx-border-color:[^;]+;", "") +
            "-fx-border-color: " + (ok ? "#7cff6b" : "#ff5555") + ";");
    }

    @FXML
    private void handleRegister() {
        // Use & (not &&) so ALL validations run and every field shows its error at once
        boolean ok = validateFullName()
                   & validateUsername()
                   & validateEmail()
                   & validatePhone()
                   & validateBirthdate()
                   & validatePassword()
                   & validateGender();

        if (!ok) {
            setStatus(regStatus, "⚠ Please fix the errors above before submitting.", "error");
            return;
        }

        String fullName   = regFullName.getText().trim();
        String username   = regUsername.getText().trim();
        String email      = regEmail.getText().trim();
        String phone      = regPhone.getText().trim().replaceAll("[\\s\\-]", "");
        String password   = regPassword.getText();
        String gender     = regGender.getValue();
        String birthdate  = regBirthdate.getValue().toString();
        String membership = regMembership.getValue();

        regButton.setDisable(true);
        setStatus(regStatus, "Registering...", "info");

        new Thread(() -> {
            try {
                JsonObject result = ApiService.registerMember(
                    fullName, username, email, phone,
                    password, gender, birthdate, membership);
                Platform.runLater(() -> {
                    regButton.setDisable(false);
                    if (result.has("success")) {
                        setStatus(regStatus, "✓ Member registered successfully! They can now log in to FitTech.", "ok");
                        handleClearForm();
                        loadAllMembers();
                        loadMembersFull();  // also refresh Members tab
                    } else {
                        setStatus(regStatus, "✗ " + safeStr(result, "error"), "error");
                    }
                });
            } catch (Exception e) {
                Platform.runLater(() -> {
                    regButton.setDisable(false);
                    setStatus(regStatus, "✗ Connection error: " + e.getMessage(), "error");
                });
            }
        }).start();
    }

    @FXML
    private void handleClearForm() {
        regFullName.clear(); regUsername.clear(); regEmail.clear();
        regPhone.clear(); regPassword.clear(); regAge.clear();
        regBirthdate.setValue(null);
        regGender.setValue(null);
        regMembership.setValue("Basic");
        regStatus.setText("");
        errFullName.setText(""); errUsername.setText(""); errEmail.setText("");
        errPhone.setText(""); errBirthdate.setText(""); errPassword.setText(""); errGender.setText("");
        for (TextField f : new TextField[]{regFullName, regUsername, regEmail, regPhone, regAge})
            f.setStyle(f.getStyle().replaceAll("-fx-border-color:[^;]+;", "") + "-fx-border-color: #333;");
        updatePasswordStrength("");
        updateMembershipPreview();
    }

    // ══════════════════════════════════════════════
    //  SCHEDULE TAB — live countdowns
    // ══════════════════════════════════════════════

    private void setupScheduleTable() {
        schMember.setCellValueFactory(r  -> str(r, "member_name"));
        schTrainer.setCellValueFactory(r -> str(r, "trainer_name"));
        schDate.setCellValueFactory(r    -> str(r, "session_date"));
        schTime.setCellValueFactory(r    -> new SimpleStringProperty(
                formatTime(safeStr(r.getValue(), "session_time"))));
        schEndTime.setCellValueFactory(r -> {
            String t = safeStr(r.getValue(), "session_end_time");
            return new SimpleStringProperty(t.isEmpty() ? "—" : formatTime(t));
        });
        schType.setCellValueFactory(r   -> str(r, "session_type"));
        schAmount.setCellValueFactory(r -> {
            String a = safeStr(r.getValue(), "amount_due");
            return new SimpleStringProperty(a.isEmpty() ? "—" : "₱" + a);
        });

        // Status with live-computed state label
        schStatus.setCellValueFactory(r -> {
            String state  = sessionState(r.getValue());
            String dbStat = safeStr(r.getValue(), "status");
            return new SimpleStringProperty(switch (state) {
                case "ACTIVE"    -> "● Active";
                case "UPCOMING"  -> "● Upcoming";
                case "COMPLETED" -> "● Done";
                default          -> "cancelled".equalsIgnoreCase(dbStat) ? "● Cancelled" : dbStat;
            });
        });

        // Live countdown — recomputed every second via scheduleTable.refresh()
        schCountdown.setCellValueFactory(r ->
                new SimpleStringProperty(buildCountdown(r.getValue())));

        // Row colour by live state
        scheduleTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override
            protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                switch (sessionState(item)) {
                    case "ACTIVE"    -> setStyle("-fx-background-color: #0a2010;");
                    case "UPCOMING"  -> setStyle("-fx-background-color: #0a1a2a;");
                    case "COMPLETED" -> setStyle("-fx-background-color: #111;");
                    default          -> setStyle("-fx-background-color: #1a0a0a;");
                }
            }
        });

        scheduleTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        scheduleTable.setItems(scheduleData);
    }

    /** Returns ACTIVE / UPCOMING / COMPLETED / CANCELLED based on current time */
    private String sessionState(JsonObject b) {
        String dbStatus = safeStr(b, "status");
        if ("cancelled".equalsIgnoreCase(dbStatus)) return "CANCELLED";

        String dateStr  = safeStr(b, "session_date");
        String startStr = safeStr(b, "session_time");
        String endStr   = safeStr(b, "session_end_time");
        if (dateStr.isEmpty() || startStr.isEmpty()) return dbStatus.toUpperCase();

        try {
            LocalDate today       = LocalDate.now();
            LocalDate sessionDate = LocalDate.parse(dateStr);
            if (sessionDate.isBefore(today)) return "COMPLETED";
            if (sessionDate.isAfter(today))  return "UPCOMING";

            LocalTime now   = LocalTime.now();
            LocalTime start = LocalTime.parse(startStr);
            LocalTime end   = endStr.isEmpty() ? start.plusHours(1) : LocalTime.parse(endStr);

            if (now.isBefore(start)) return "UPCOMING";
            if (now.isAfter(end))    return "COMPLETED";
            return "ACTIVE";
        } catch (Exception e) {
            return "UPCOMING";
        }
    }

    /** Builds the countdown string for the ⏱ column */
    private String buildCountdown(JsonObject b) {
        String state    = sessionState(b);
        String dateStr  = safeStr(b, "session_date");
        String startStr = safeStr(b, "session_time");
        String endStr   = safeStr(b, "session_end_time");
        if (dateStr.isEmpty() || startStr.isEmpty()) return "—";

        try {
            LocalDate today       = LocalDate.now();
            LocalDate sessionDate = LocalDate.parse(dateStr);
            LocalTime now   = LocalTime.now();
            LocalTime start = LocalTime.parse(startStr);
            LocalTime end   = endStr.isEmpty() ? start.plusHours(1) : LocalTime.parse(endStr);

            return switch (state) {
                case "ACTIVE" -> {
                    long secLeft = ChronoUnit.SECONDS.between(now, end);
                    if (secLeft <= 0) yield "Ending...";
                    long m = secLeft / 60, s = secLeft % 60;
                    yield String.format("⏱ %d:%02d left", m, s);
                }
                case "UPCOMING" -> {
                    if (sessionDate.equals(today)) {
                        long secToStart = ChronoUnit.SECONDS.between(now, start);
                        long m = secToStart / 60, s = secToStart % 60;
                        yield secToStart < 3600
                            ? String.format("Starts in %d:%02d", m, s)
                            : String.format("Starts in %dh %dm", m / 60, m % 60);
                    } else {
                        long days = ChronoUnit.DAYS.between(today, sessionDate);
                        yield "In " + days + " day" + (days == 1 ? "" : "s");
                    }
                }
                case "COMPLETED" -> "✓ Done";
                default          -> "—";
            };
        } catch (Exception e) {
            return "—";
        }
    }

    private void loadTodaySchedule() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonArray bookings = ApiService.getTodayBookings(adminId);
                Platform.runLater(() -> {
                    scheduleData.clear();
                    bookings.forEach(b -> scheduleData.add(b.getAsJsonObject()));
                    scheduleStatus.setText("Last updated: " +
                        LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm:ss a")) +
                        " — " + scheduleData.size() + " booking(s)");
                    updateActiveCount();
                });
            } catch (Exception e) {
                Platform.runLater(() -> scheduleStatus.setText("Failed to load: " + e.getMessage()));
            }
        }).start();
    }

    @FXML
    private void handleRefreshSchedule() {
        scheduleStatus.setText("Refreshing...");
        loadTodaySchedule();
    }

    // ══════════════════════════════════════════════
    //  PENDING ORDERS TAB
    // ══════════════════════════════════════════════

    private void setupCartTable() {
        cartColOrderId.setCellValueFactory(r    -> str(r, "id"));
        cartColName.setCellValueFactory(r       -> str(r, "member_name"));
        cartColUsername.setCellValueFactory(r   -> str(r, "username"));
        cartColMembership.setCellValueFactory(r -> str(r, "membership_type"));
        cartColItems.setCellValueFactory(r      -> str(r, "total_items"));
        cartColValue.setCellValueFactory(r      -> {
            String v = safeStr(r.getValue(), "total_amount");
            return new SimpleStringProperty(v.isEmpty() ? "—" : "₱" + formatAmount(v));
        });
        cartColDetail.setCellValueFactory(r -> str(r, "items_summary"));
        cartColDate.setCellValueFactory(r   -> new SimpleStringProperty(
                formatDateTime(safeStr(r.getValue(), "created_at"))));

        // Row tint — highlight high-value orders
        cartTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override
            protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                try {
                    double val = Double.parseDouble(safeStr(item, "total_amount"));
                    setStyle(val >= 1000 ? "-fx-background-color: #0a1a0a;" : "");
                } catch (Exception e) { setStyle(""); }
            }
        });

        cartTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        cartTable.setItems(cartData);
    }

    private void loadCartAnalytics() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonArray data = ApiService.getPendingOrders(adminId);
                Platform.runLater(() -> {
                    cartData.clear();
                    data.forEach(d -> cartData.add(d.getAsJsonObject()));

                    double totalVal = 0;
                    int totalItems  = 0;
                    for (JsonObject row : cartData) {
                        try { totalVal  += Double.parseDouble(safeStr(row, "total_amount")); } catch (Exception ignored) {}
                        try { totalItems += Integer.parseInt(safeStr(row, "total_items"));  } catch (Exception ignored) {}
                    }
                    cartSummaryLabel.setText(cartData.size() + " order(s) · "
                        + totalItems + " item(s) · ₱" + formatAmount(String.valueOf(totalVal)));
                    cartStatus.setText("Last updated: " +
                        LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm:ss a")));
                });
            } catch (Exception e) {
                Platform.runLater(() -> cartStatus.setText("Failed to load: " + e.getMessage()));
            }
        }).start();
    }

    @FXML
    private void handleRefreshCart() {
        cartStatus.setText("Refreshing...");
        loadCartAnalytics();
    }

    @FXML
    private void handleApproveOrder() {
        JsonObject selected = cartTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            setStatus(cartStatus, "⚠ Select an order first.", "warn");
            return;
        }
        int orderId = selected.get("id").getAsInt();
        String memberName = safeStr(selected, "member_name");
        String amount     = safeStr(selected, "total_amount");

        Alert confirm = new Alert(Alert.AlertType.CONFIRMATION);
        confirm.setTitle("Approve Order");
        confirm.setHeaderText("Approve Order #" + orderId + " for " + memberName + "?");
        confirm.setContentText("Amount: ₱" + formatAmount(amount) + "\nThis will deduct stock and mark the order as confirmed.");
        styleDialog(confirm);
        confirm.showAndWait().ifPresent(btn -> {
            if (btn == ButtonType.OK) {
                cartApproveBtn.setDisable(true);
                cartRejectBtn.setDisable(true);
                setStatus(cartStatus, "Approving...", "info");
                new Thread(() -> {
                    try {
                        JsonObject result = ApiService.approveOrder(orderId);
                        Platform.runLater(() -> {
                            cartApproveBtn.setDisable(false);
                            cartRejectBtn.setDisable(false);
                            if (result.has("success")) {
                                setStatus(cartStatus, "✓ Order #" + orderId + " approved.", "ok");
                                loadCartAnalytics();
                            } else {
                                setStatus(cartStatus, "✗ " + safeStr(result, "error"), "error");
                            }
                        });
                    } catch (Exception e) {
                        Platform.runLater(() -> {
                            cartApproveBtn.setDisable(false);
                            cartRejectBtn.setDisable(false);
                            setStatus(cartStatus, "✗ Connection error: " + e.getMessage(), "error");
                        });
                    }
                }).start();
            }
        });
    }

    @FXML
    private void handleRejectOrder() {
        JsonObject selected = cartTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            setStatus(cartStatus, "⚠ Select an order first.", "warn");
            return;
        }
        int orderId   = selected.get("id").getAsInt();
        String memberName = safeStr(selected, "member_name");

        Alert confirm = new Alert(Alert.AlertType.CONFIRMATION);
        confirm.setTitle("Reject Order");
        confirm.setHeaderText("Reject Order #" + orderId + " for " + memberName + "?");
        confirm.setContentText("This will cancel the order. The member will see it as cancelled.");
        styleDialog(confirm);
        confirm.showAndWait().ifPresent(btn -> {
            if (btn == ButtonType.OK) {
                cartApproveBtn.setDisable(true);
                cartRejectBtn.setDisable(true);
                setStatus(cartStatus, "Rejecting...", "info");
                new Thread(() -> {
                    try {
                        JsonObject result = ApiService.rejectOrder(orderId);
                        Platform.runLater(() -> {
                            cartApproveBtn.setDisable(false);
                            cartRejectBtn.setDisable(false);
                            if (result.has("success")) {
                                setStatus(cartStatus, "✗ Order #" + orderId + " rejected.", "warn");
                                loadCartAnalytics();
                            } else {
                                setStatus(cartStatus, "✗ " + safeStr(result, "error"), "error");
                            }
                        });
                    } catch (Exception e) {
                        Platform.runLater(() -> {
                            cartApproveBtn.setDisable(false);
                            cartRejectBtn.setDisable(false);
                            setStatus(cartStatus, "✗ Connection error: " + e.getMessage(), "error");
                        });
                    }
                }).start();
            }
        });
    }

    // ══════════════════════════════════════════════
    //  PURCHASE ANALYTICS TAB
    // ══════════════════════════════════════════════

    private void setupPurchaseTable() {
        purColName.setCellValueFactory(r       -> str(r, "member_name"));
        purColUsername.setCellValueFactory(r   -> str(r, "username"));
        purColMembership.setCellValueFactory(r -> str(r, "membership_type"));
        purColOrders.setCellValueFactory(r     -> str(r, "total_orders"));
        purColItems.setCellValueFactory(r      -> str(r, "total_items_bought"));
        purColSpent.setCellValueFactory(r      -> {
            String v = safeStr(r.getValue(), "total_spent");
            return new SimpleStringProperty(v.isEmpty() ? "—" : "₱" + formatAmount(v));
        });
        purColFirst.setCellValueFactory(r -> new SimpleStringProperty(
                formatDateTime(safeStr(r.getValue(), "first_purchase"))));
        purColLast.setCellValueFactory(r  -> new SimpleStringProperty(
                formatDateTime(safeStr(r.getValue(), "last_purchase"))));
        purColDetail.setCellValueFactory(r -> str(r, "purchase_detail"));

        // Row tint — top spender gets gold highlight
        purchaseTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override
            protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                setStyle(getIndex() == 0 ? "-fx-background-color: #1a1500;" : "");
            }
        });

        purchaseTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        purchaseTable.setItems(purchaseData);
    }

    private void loadPurchaseAnalytics() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonArray data = ApiService.getPurchaseAnalytics(adminId);
                Platform.runLater(() -> {
                    purchaseData.clear();
                    data.forEach(d -> purchaseData.add(d.getAsJsonObject()));

                    double grandTotal = 0;
                    int    grandItems = 0;
                    for (JsonObject row : purchaseData) {
                        try { grandTotal += Double.parseDouble(safeStr(row, "total_spent")); }      catch (Exception ignored) {}
                        try { grandItems += Integer.parseInt(safeStr(row, "total_items_bought")); } catch (Exception ignored) {}
                    }
                    purchaseSummaryLabel.setText(purchaseData.size() + " buyer(s) · "
                        + grandItems + " item(s) · ₱" + formatAmount(String.valueOf(grandTotal)));
                    purchaseStatus.setText("Last updated: " +
                        LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm:ss a")));
                });
            } catch (Exception e) {
                Platform.runLater(() -> purchaseStatus.setText("Failed to load: " + e.getMessage()));
            }
        }).start();
    }

    @FXML
    private void handleRefreshPurchases() {
        purchaseStatus.setText("Refreshing...");
        loadPurchaseAnalytics();
    }

    // ══════════════════════════════════════════════
    //  MEMBERS TAB — editable
    // ══════════════════════════════════════════════

    private void setupMembersTab() {
        mColId.setCellValueFactory(r         -> str(r, "id"));
        mColName.setCellValueFactory(r       -> str(r, "full_name"));
        mColUsername.setCellValueFactory(r   -> str(r, "username"));
        mColEmail.setCellValueFactory(r      -> str(r, "email"));
        mColPhone.setCellValueFactory(r      -> str(r, "phone"));
        mColGender.setCellValueFactory(r     -> str(r, "gender"));
        mColBirthdate.setCellValueFactory(r  -> new SimpleStringProperty(
                formatDate(safeStr(r.getValue(), "birthdate"))));
        mColMembership.setCellValueFactory(r -> str(r, "membership_type"));
        mColStatus.setCellValueFactory(r     -> str(r, "status"));
        mColExpiry.setCellValueFactory(r     -> new SimpleStringProperty(
                formatDate(safeStr(r.getValue(), "expiry_date"))));

        mColAge.setCellValueFactory(r -> {
            String bd = safeStr(r.getValue(), "birthdate");
            if (bd.isEmpty()) return new SimpleStringProperty("—");
            try {
                return new SimpleStringProperty(
                    String.valueOf(Period.between(LocalDate.parse(bd), LocalDate.now()).getYears()));
            } catch (Exception e) { return new SimpleStringProperty("—"); }
        });

        mColDaysLeft.setCellValueFactory(r -> {
            String exp = safeStr(r.getValue(), "expiry_date");
            if (exp.isEmpty()) return new SimpleStringProperty("—");
            try {
                long days = ChronoUnit.DAYS.between(LocalDate.now(), LocalDate.parse(exp));
                if (days < 0)  return new SimpleStringProperty("EXPIRED");
                if (days == 0) return new SimpleStringProperty("Today!");
                return new SimpleStringProperty(days + "d");
            } catch (Exception e) { return new SimpleStringProperty("—"); }
        });

        // Row colour coding
        membersTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override
            protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                String status = safeStr(item, "status");
                long days = Long.MAX_VALUE;
                try {
                    String exp = safeStr(item, "expiry_date");
                    if (!exp.isEmpty())
                        days = ChronoUnit.DAYS.between(LocalDate.now(), LocalDate.parse(exp));
                } catch (Exception ignored) {}
                if ("Expired".equalsIgnoreCase(status) || days < 0) setStyle("-fx-background-color: #2a1010;");
                else if (days <= 7)                                  setStyle("-fx-background-color: #2a2000;");
                else                                                  setStyle("");
            }
        });

        membersTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        membersTable.setItems(membersFiltered);

        // Edit panel combos
        editGender.setItems(FXCollections.observableArrayList("Male", "Female"));
        editMembership.setItems(FXCollections.observableArrayList("Basic", "Premium", "VIP"));
        editStatus.setItems(FXCollections.observableArrayList("Active", "Inactive", "Expired"));

        // Hide edit panel initially
        memberEditPanel.setVisible(false);
        memberEditPanel.setManaged(false);
    }

    private void loadMembersFull() {
        int adminId = SessionManager.getInstance().getAdminId();
        membersTabStatus.setText("Loading…");
        new Thread(() -> {
            try {
                JsonArray members = ApiService.getAllMembers(adminId);
                Platform.runLater(() -> {
                    allMembersFull.clear();
                    members.forEach(m -> allMembersFull.add(m.getAsJsonObject()));
                    applyMembersFilter(membersSearchField.getText().trim());
                    membersTabCount.setText("(" + allMembersFull.size() + " total)");
                    membersTabStatus.setText("Loaded " + allMembersFull.size() + " members.");
                });
            } catch (Exception e) {
                Platform.runLater(() -> membersTabStatus.setText("✗ Failed: " + e.getMessage()));
            }
        }).start();
    }

    @FXML
    private void handleRefreshMembersTab() {
        hideEditPanel();
        loadMembersFull();
    }

    @FXML
    private void handleMembersSearch() {
        applyMembersFilter(membersSearchField.getText().trim());
    }

    private void applyMembersFilter(String query) {
        if (query.isEmpty()) {
            membersFiltered.setAll(allMembersFull);
            return;
        }
        String q = query.toLowerCase();
        membersFiltered.setAll(allMembersFull.stream().filter(m -> {
            // compute days-left string
            String daysStr = "";
            String exp = safeStr(m, "expiry_date");
            if (!exp.isEmpty()) {
                try {
                    long days = ChronoUnit.DAYS.between(LocalDate.now(), LocalDate.parse(exp));
                    daysStr = days < 0 ? "expired" : days == 0 ? "today" : days + "d";
                } catch (Exception ignored) {}
            }
            // compute age string
            String ageStr = "";
            String bd = safeStr(m, "birthdate");
            if (!bd.isEmpty()) {
                try { ageStr = String.valueOf(Period.between(LocalDate.parse(bd), LocalDate.now()).getYears()); }
                catch (Exception ignored) {}
            }
            return safeStr(m, "full_name").toLowerCase().contains(q)      ||
                   safeStr(m, "username").toLowerCase().contains(q)        ||
                   safeStr(m, "email").toLowerCase().contains(q)           ||
                   safeStr(m, "phone").toLowerCase().contains(q)           ||
                   safeStr(m, "membership_type").toLowerCase().contains(q) ||
                   safeStr(m, "status").toLowerCase().contains(q)          ||
                   safeStr(m, "gender").toLowerCase().contains(q)          ||
                   formatDate(exp).toLowerCase().contains(q)               ||
                   formatDate(bd).toLowerCase().contains(q)                ||
                   daysStr.contains(q)                                     ||
                   ageStr.contains(q)                                      ||
                   safeStr(m, "id").contains(q);
        }).toList());
    }

    /** Click a row → open edit panel pre-filled */
    @FXML
    private void handleMembersTableClick(MouseEvent event) {
        JsonObject selected = membersTable.getSelectionModel().getSelectedItem();
        if (selected == null) return;
        currentlyEditing = selected;
        populateEditPanel(selected);
        showEditPanel();
    }

    private void populateEditPanel(JsonObject m) {
        editMemberId.setText("ID #" + safeStr(m, "id") + " — " + safeStr(m, "full_name"));
        editFullName.setText(safeStr(m, "full_name"));
        editUsername.setText(safeStr(m, "username"));
        editEmail.setText(safeStr(m, "email"));
        editPhone.setText(safeStr(m, "phone"));

        String gender = safeStr(m, "gender");
        editGender.setValue(gender.isEmpty() ? null : gender);

        String membership = safeStr(m, "membership_type");
        editMembership.setValue(membership.isEmpty() ? "Basic" : membership);

        String status = safeStr(m, "status");
        editStatus.setValue(status.isEmpty() ? "Active" : status);

        String bd = safeStr(m, "birthdate");
        try { editBirthdate.setValue(bd.isEmpty() ? null : LocalDate.parse(bd)); }
        catch (Exception e) { editBirthdate.setValue(null); }

        String exp = safeStr(m, "expiry_date");
        try { editExpiry.setValue(exp.isEmpty() ? null : LocalDate.parse(exp)); }
        catch (Exception e) { editExpiry.setValue(null); }

        // Clear all error labels and status message
        editErrFullName.setText(""); editErrUsername.setText(""); editErrEmail.setText("");
        editErrPhone.setText(""); editErrGender.setText(""); editErrBirthdate.setText("");
        editStatusLabel.setText("");
    }

    private void showEditPanel() {
        memberEditPanel.setVisible(true);
        memberEditPanel.setManaged(true);
    }

    private void hideEditPanel() {
        memberEditPanel.setVisible(false);
        memberEditPanel.setManaged(false);
        currentlyEditing = null;
        membersTable.getSelectionModel().clearSelection();
    }

    @FXML
    private void handleCancelEdit() { hideEditPanel(); }

    /** Validate all edit-panel fields; returns true only if every field passes */
    private boolean validateEditPanel() {
        boolean ok = true;

        String fullName = editFullName.getText().trim();
        if (fullName.length() < 2) {
            editErrFullName.setText("Min 2 characters."); ok = false;
        } else if (fullName.length() > 100) {
            editErrFullName.setText("Max 100 characters."); ok = false;
        } else if (!fullName.matches("[a-zA-ZÀ-ÿ\\s\\-\\.]+")) {
            editErrFullName.setText("Letters, spaces, hyphens and periods only."); ok = false;
        } else editErrFullName.setText("");

        String username = editUsername.getText().trim();
        if (!USERNAME_PATTERN.matcher(username).matches()) {
            editErrUsername.setText("3–20 chars, letters/numbers/underscores."); ok = false;
        } else editErrUsername.setText("");

        String email = editEmail.getText().trim();
        if (!GMAIL_PATTERN.matcher(email).matches()) {
            editErrEmail.setText("Must be a valid Gmail address."); ok = false;
        } else editErrEmail.setText("");

        String phone = editPhone.getText().trim().replaceAll("[\\s\\-]", "");
        if (!PHONE_PATTERN.matcher(phone).matches()) {
            editErrPhone.setText("Invalid Philippine number. Use 09XXXXXXXXX or +639XXXXXXXXX with a valid network prefix."); ok = false;
        } else editErrPhone.setText("");

        if (editGender.getValue() == null || editGender.getValue().isEmpty()) {
            editErrGender.setText("Required."); ok = false;
        } else editErrGender.setText("");

        if (editBirthdate.getValue() == null) {
            editErrBirthdate.setText("Required."); ok = false;
        } else {
            LocalDate bd = editBirthdate.getValue();
            if (!bd.isBefore(LocalDate.now())) {
                editErrBirthdate.setText("Cannot be today or in the future."); ok = false;
            } else {
                int age = Period.between(bd, LocalDate.now()).getYears();
                if (age < 15 || age > 80) {
                    editErrBirthdate.setText("Age must be 15–80."); ok = false;
                } else editErrBirthdate.setText("");
            }
        }

        return ok;
    }

    @FXML
    private void handleSaveMember() {
        if (currentlyEditing == null) return;
        if (!validateEditPanel()) {
            setStatus(editStatusLabel, "⚠ Fix the errors above before saving.", "error");
            return;
        }

        int    memberId   = currentlyEditing.get("id").getAsInt();
        String fullName   = editFullName.getText().trim();
        String username   = editUsername.getText().trim();
        String email      = editEmail.getText().trim();
        String phone      = editPhone.getText().trim().replaceAll("[\\s\\-]", "");
        String gender     = editGender.getValue();
        String membership = editMembership.getValue();
        String status     = editStatus.getValue();     // correctly reads from ComboBox
        String birthdate  = editBirthdate.getValue() != null ? editBirthdate.getValue().toString() : "";
        String expiry     = editExpiry.getValue()    != null ? editExpiry.getValue().toString()    : "";

        editSaveBtn.setDisable(true);
        setStatus(editStatusLabel, "Saving…", "info");

        new Thread(() -> {
            try {
                JsonObject result = ApiService.updateMember(
                    memberId, fullName, username, email, phone,
                    gender, birthdate, membership, status, expiry);
                Platform.runLater(() -> {
                    editSaveBtn.setDisable(false);
                    if (result.has("success")) {
                        setStatus(editStatusLabel, "✓ Member updated successfully!", "ok");
                        loadAllMembers();   // refresh Check-In tab
                        loadMembersFull();  // refresh Members tab
                        hideEditPanel();
                    } else {
                        setStatus(editStatusLabel, "✗ " + safeStr(result, "error"), "error");
                    }
                });
            } catch (Exception e) {
                Platform.runLater(() -> {
                    editSaveBtn.setDisable(false);
                    setStatus(editStatusLabel, "✗ Connection error: " + e.getMessage(), "error");
                });
            }
        }).start();
    }

    // ══════════════════════════════════════════════
    //  RENEWAL TAB
    // ══════════════════════════════════════════════

    private void setupRenewalTab() {
        // renewal.php?action=pending returns fields from the renewal_requests table
        // joined with members: member_name, username, current_type, requested_type,
        // status, live_expiry, amount, created_at
        renColId        .setCellValueFactory(r -> str(r, "id"));
        renColName      .setCellValueFactory(r -> str(r, "member_name"));
        renColUsername  .setCellValueFactory(r -> str(r, "username"));
        // Show "CurrentType → RequestedType" in the membership column
        renColMembership.setCellValueFactory(r -> new SimpleStringProperty(
            safeStr(r.getValue(), "current_type") + " → " + safeStr(r.getValue(), "requested_type")));
        renColStatus    .setCellValueFactory(r -> str(r, "status"));
        // live_expiry = member's current real expiry (from the JOIN)
        renColExpiry    .setCellValueFactory(r -> new SimpleStringProperty(
                formatDate(safeStr(r.getValue(), "live_expiry"))));
        // Phone and email come from renewal_requests fields or member join
        renColPhone     .setCellValueFactory(r -> new SimpleStringProperty(
            "₱" + formatAmount(safeStr(r.getValue(), "amount"))));
        renColEmail     .setCellValueFactory(r -> new SimpleStringProperty(
            formatDateTime(safeStr(r.getValue(), "created_at"))));

        renColDaysLeft.setCellValueFactory(r -> {
            String exp = safeStr(r.getValue(), "live_expiry");
            if (exp.isEmpty()) return new SimpleStringProperty("—");
            try {
                long days = ChronoUnit.DAYS.between(LocalDate.now(), LocalDate.parse(exp));
                if (days < 0)  return new SimpleStringProperty("EXPIRED " + Math.abs(days) + "d ago");
                if (days == 0) return new SimpleStringProperty("Today!");
                return new SimpleStringProperty(days + "d left");
            } catch (Exception e) { return new SimpleStringProperty("—"); }
        });

        // Row colour by request status
        renewalTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                switch (safeStr(item, "status").toLowerCase()) {
                    case "pending"  -> setStyle("-fx-background-color: #1a1a00;");
                    case "approved" -> setStyle("-fx-background-color: #0a1a0a;");
                    case "rejected" -> setStyle("-fx-background-color: #2a1010;");
                    case "refunded" -> setStyle("-fx-background-color: #1a0a1a;");
                    default         -> setStyle("");
                }
            }
        });

        renewalTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        renewalTable.setItems(renewalData);

        renewMembershipCombo.setItems(FXCollections.observableArrayList("Basic", "Premium", "VIP"));
        renewMembershipCombo.setValue("Basic");
    }

    // currentRenewalFilter tracks "pending" or "all" for the filter ComboBox
    private String currentRenewalFilter = "pending";

    private void loadRenewalData() {
        int adminId = SessionManager.getInstance().getAdminId();
        setStatus(renewalStatus, "Loading…", "info");
        new Thread(() -> {
            try {
                JsonArray requests = ApiService.getRenewalRequests(adminId, currentRenewalFilter);
                Platform.runLater(() -> {
                    renewalAll.clear();
                    requests.forEach(e -> renewalAll.add(e.getAsJsonObject()));
                    renewalData.setAll(renewalAll);

                    long pending  = renewalAll.stream()
                        .filter(r -> "pending".equalsIgnoreCase(safeStr(r, "status"))).count();
                    long approved = renewalAll.stream()
                        .filter(r -> "approved".equalsIgnoreCase(safeStr(r, "status"))).count();
                    renewalSummaryLabel.setText(pending + " pending · " + approved + " approved");

                    setStatus(renewalStatus,
                        "Loaded " + renewalAll.size() + " request(s) — "
                        + LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")), "info");
                });
            } catch (Exception e) {
                Platform.runLater(() -> setStatus(renewalStatus, "✗ " + e.getMessage(), "error"));
            }
        }).start();
    }

    @FXML private void handleRefreshRenewal() {
        loadRenewalData();
    }

    // These three handlers map to the filter buttons in the FXML
    @FXML private void handleRenewalFilterAll() {
        currentRenewalFilter = "all";
        loadRenewalData();
    }

    @FXML private void handleRenewalFilterExpired() {
        // "Expired" in the context of renewal requests = show only pending
        currentRenewalFilter = "pending";
        loadRenewalData();
    }

    @FXML private void handleRenewalFilterSoon() {
        // Repurposed: show all (including approved/rejected history)
        currentRenewalFilter = "all";
        loadRenewalData();
    }

    /** Approve the selected pending renewal request */
    @FXML private void handleRenewMember() {
        JsonObject selected = renewalTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            setStatus(renewalStatus, "⚠ Select a request first.", "warn"); return;
        }
        if (!"pending".equalsIgnoreCase(safeStr(selected, "status"))) {
            setStatus(renewalStatus, "⚠ Only pending requests can be approved.", "warn"); return;
        }

        int    requestId     = selected.get("id").getAsInt();
        String memberName    = safeStr(selected, "member_name");
        String requestedPlan = safeStr(selected, "requested_type");

        Alert confirm = new Alert(Alert.AlertType.CONFIRMATION);
        confirm.setTitle("Approve Renewal");
        confirm.setHeaderText("Approve " + memberName + "'s request → " + requestedPlan + "?");
        confirm.setContentText("Their membership will be extended immediately."
            + "Any remaining days will be carried forward.");
        styleDialog(confirm);
        confirm.showAndWait().ifPresent(btn -> {
            if (btn != ButtonType.OK) return;
            setStatus(renewalStatus, "Approving…", "info");
            new Thread(() -> {
                try {
                    JsonObject result = ApiService.approveRenewal(requestId);
                    Platform.runLater(() -> {
                        if (result.has("success")) {
                            String newExpiry = safeStr(result, "new_expiry");
                            setStatus(renewalStatus,
                                "✓ " + memberName + " approved → " + requestedPlan
                                + " (expires " + newExpiry + ")", "ok");
                            loadRenewalData();
                            loadAllMembers();
                            loadMembersFull();
                            loadStats();
                        } else {
                            setStatus(renewalStatus, "✗ " + safeStr(result, "error"), "error");
                        }
                    });
                } catch (Exception e) {
                    Platform.runLater(() -> setStatus(renewalStatus, "✗ " + e.getMessage(), "error"));
                }
            }).start();
        });
    }

    /** Reject the selected pending renewal request */
    @FXML private void handleRejectRenewal() {
        JsonObject selected = renewalTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            setStatus(renewalStatus, "⚠ Select a request first.", "warn"); return;
        }
        if (!"pending".equalsIgnoreCase(safeStr(selected, "status"))) {
            setStatus(renewalStatus, "⚠ Only pending requests can be rejected.", "warn"); return;
        }

        int    requestId  = selected.get("id").getAsInt();
        String memberName = safeStr(selected, "member_name");

        Alert confirm = new Alert(Alert.AlertType.CONFIRMATION);
        confirm.setTitle("Reject Renewal");
        confirm.setHeaderText("Reject renewal request #" + requestId + " from " + memberName + "?");
        confirm.setContentText("The member will see their request as rejected. No membership changes are made.");
        styleDialog(confirm);
        confirm.showAndWait().ifPresent(btn -> {
            if (btn != ButtonType.OK) return;
            setStatus(renewalStatus, "Rejecting…", "info");
            new Thread(() -> {
                try {
                    JsonObject result = ApiService.rejectRenewal(requestId, "Rejected by admin");
                    Platform.runLater(() -> {
                        if (result.has("success")) {
                            setStatus(renewalStatus, "✗ Request #" + requestId + " rejected.", "warn");
                            loadRenewalData();
                        } else {
                            setStatus(renewalStatus, "✗ " + safeStr(result, "error"), "error");
                        }
                    });
                } catch (Exception e) {
                    Platform.runLater(() -> setStatus(renewalStatus, "✗ " + e.getMessage(), "error"));
                }
            }).start();
        });
    }

    /** Refund (unwind) an approved renewal request */
    @FXML private void handleRefundRenewal() {
        JsonObject selected = renewalTable.getSelectionModel().getSelectedItem();
        if (selected == null) {
            setStatus(renewalStatus, "⚠ Select a request first.", "warn"); return;
        }
        if (!"approved".equalsIgnoreCase(safeStr(selected, "status"))) {
            setStatus(renewalStatus, "⚠ Only approved renewals can be refunded.", "warn"); return;
        }

        int    requestId  = selected.get("id").getAsInt();
        String memberName = safeStr(selected, "member_name");
        String amount     = safeStr(selected, "amount");

        Alert confirm = new Alert(Alert.AlertType.CONFIRMATION);
        confirm.setTitle("Refund Renewal");
        confirm.setHeaderText("Refund request #" + requestId + " for " + memberName + "?");
        confirm.setContentText("Amount: ₱" + amount + "\n\n"
            + "This will roll back the member's membership to their state before this approval.\n"
            + "Any renewals stacked on top of this one will also be unwound.");
        styleDialog(confirm);
        confirm.showAndWait().ifPresent(btn -> {
            if (btn != ButtonType.OK) return;
            setStatus(renewalStatus, "Processing refund…", "info");
            new Thread(() -> {
                try {
                    JsonObject result = ApiService.refundRenewal(requestId, "Refunded by admin");
                    Platform.runLater(() -> {
                        if (result.has("success")) {
                            int    chainCount     = result.has("chain_count") ? result.get("chain_count").getAsInt() : 1;
                            double totalRefunded  = result.has("total_refunded") ? result.get("total_refunded").getAsDouble() : 0;
                            String revertedTo     = safeStr(result, "reverted_to_type");
                            setStatus(renewalStatus,
                                "↩ Refunded ₱" + formatAmount(String.valueOf(totalRefunded))
                                + " · " + chainCount + " renewal(s) unwound · "
                                + memberName + " reverted to " + revertedTo, "warn");
                            loadRenewalData();
                            loadAllMembers();
                            loadMembersFull();
                            loadStats();
                        } else {
                            setStatus(renewalStatus, "✗ " + safeStr(result, "error"), "error");
                        }
                    });
                } catch (Exception e) {
                    Platform.runLater(() -> setStatus(renewalStatus, "✗ " + e.getMessage(), "error"));
                }
            }).start();
        });
    }

    // ══════════════════════════════════════════════
    //  EQUIPMENT TAB — Add / Edit / Delete / Search
    // ══════════════════════════════════════════════

    private final ObservableList<JsonObject> equipAllData = FXCollections.observableArrayList();

    private void setupEquipmentTab() {
        equipColId       .setCellValueFactory(r -> str(r, "id"));
        equipColName     .setCellValueFactory(r -> str(r, "name"));
        equipColCategory .setCellValueFactory(r -> str(r, "category"));
        equipColCondition.setCellValueFactory(r -> str(r, "condition"));
        equipColQty      .setCellValueFactory(r -> str(r, "quantity"));
        equipColLocation .setCellValueFactory(r -> str(r, "location"));
        equipColLastCheck.setCellValueFactory(r -> new SimpleStringProperty(
                formatDateTime(safeStr(r.getValue(), "last_checked"))));
        equipColNotes    .setCellValueFactory(r -> str(r, "notes"));

        // Row colour by condition
        equipTable.setRowFactory(tv -> new TableRow<JsonObject>() {
            @Override protected void updateItem(JsonObject item, boolean empty) {
                super.updateItem(item, empty);
                if (empty || item == null) { setStyle(""); return; }
                switch (safeStr(item, "condition")) {
                    case "Poor", "Out of Service" -> setStyle("-fx-background-color: #2a1010;");
                    case "Needs Repair"           -> setStyle("-fx-background-color: #2a1a00;");
                    case "Fair"                   -> setStyle("-fx-background-color: #1a1a00;");
                    default                       -> setStyle("");
                }
            }
        });

        equipTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        equipTable.setItems(equipData);

        equipCategoryCombo.setItems(FXCollections.observableArrayList(
            "Cardio", "Strength", "Free Weights", "Cables & Pulleys",
            "Stretching", "Boxing", "Storage", "Other"));
        equipConditionCombo.setItems(FXCollections.observableArrayList(
            "Excellent", "Good", "Fair", "Needs Repair", "Poor", "Out of Service"));

        hideEquipPanel();
    }

    private void loadEquipment() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonArray arr = ApiService.getEquipment(adminId);
                Platform.runLater(() -> {
                    equipAllData.clear();
                    arr.forEach(el -> equipAllData.add(el.getAsJsonObject()));
                    applyEquipFilter(equipSearchField.getText().trim());
                    long poor = equipAllData.stream().filter(m -> {
                        String c = safeStr(m, "condition");
                        return "Poor".equals(c) || "Needs Repair".equals(c) || "Out of Service".equals(c);
                    }).count();
                    equipSummaryLabel.setText(equipAllData.size() + " items · " + poor + " need attention");
                    setStatus(equipStatusFooter, "Last updated: " +
                        LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")), "info");
                });
            } catch (Exception e) {
                Platform.runLater(() -> setStatus(equipStatusFooter, "✗ " + e.getMessage(), "error"));
            }
        }).start();
    }

    @FXML private void handleEquipSearch() {
        applyEquipFilter(equipSearchField.getText().trim());
    }

    private void applyEquipFilter(String q) {
        if (q.isEmpty()) { equipData.setAll(equipAllData); return; }
        String lq = q.toLowerCase();
        equipData.setAll(equipAllData.stream().filter(m ->
            safeStr(m, "name").toLowerCase().contains(lq) ||
            safeStr(m, "category").toLowerCase().contains(lq) ||
            safeStr(m, "location").toLowerCase().contains(lq)
        ).toList());
    }

    @FXML private void handleRefreshEquipment() { hideEquipPanel(); loadEquipment(); }

    /** Opens panel in ADD mode (blank form) */
    @FXML private void handleNewEquip() {
        equipIsAdding = true;
        currentlyEditingEquip = null;
        equipEditName.setText("＋ Add New Equipment");
        equipNameField.clear();
        equipCategoryCombo.setValue(null);
        equipQtyField.setText("1");
        equipConditionCombo.setValue("Good");
        equipLocationField.clear();
        equipNotesField.clear();
        equipErrName.setText(""); equipErrCategory.setText(""); equipErrQty.setText("");
        equipDeleteBtn.setVisible(false);
        equipDeleteBtn.setManaged(false);
        setStatus(equipStatusLabel, "", "info");
        showEquipPanel();
    }

    /** Opens panel in EDIT mode, pre-filled from the clicked row */
    @FXML private void handleEquipTableClick(MouseEvent event) {
        JsonObject sel = equipTable.getSelectionModel().getSelectedItem();
        if (sel == null) return;
        equipIsAdding = false;
        currentlyEditingEquip = sel;
        equipEditName.setText("✏  Edit: " + safeStr(sel, "name"));
        equipNameField.setText(safeStr(sel, "name"));
        equipCategoryCombo.setValue(safeStr(sel, "category"));
        equipQtyField.setText(safeStr(sel, "quantity"));
        equipConditionCombo.setValue(safeStr(sel, "condition"));
        equipLocationField.setText(safeStr(sel, "location"));
        equipNotesField.setText(safeStr(sel, "notes"));
        equipErrName.setText(""); equipErrCategory.setText(""); equipErrQty.setText("");
        equipDeleteBtn.setVisible(true);
        equipDeleteBtn.setManaged(true);
        setStatus(equipStatusLabel, "", "info");
        showEquipPanel();
    }

    @FXML private void handleCancelEquipEdit() {
        hideEquipPanel();
        equipTable.getSelectionModel().clearSelection();
    }

    private boolean validateEquipForm() {
        boolean ok = true;
        if (equipNameField.getText().trim().isEmpty()) {
            equipErrName.setText("Name is required."); ok = false;
        } else { equipErrName.setText(""); }
        if (equipCategoryCombo.getValue() == null) {
            equipErrCategory.setText("Select a category."); ok = false;
        } else { equipErrCategory.setText(""); }
        try {
            int qty = Integer.parseInt(equipQtyField.getText().trim());
            if (qty < 0) { equipErrQty.setText("Must be ≥ 0."); ok = false; }
            else equipErrQty.setText("");
        } catch (NumberFormatException e) {
            equipErrQty.setText("Must be a whole number."); ok = false;
        }
        return ok;
    }

    @FXML private void handleSaveEquip() {
        if (!validateEquipForm()) return;
        String name      = equipNameField.getText().trim();
        String category  = equipCategoryCombo.getValue();
        int    qty       = Integer.parseInt(equipQtyField.getText().trim());
        String condition = equipConditionCombo.getValue() != null ? equipConditionCombo.getValue() : "Good";
        String location  = equipLocationField.getText().trim();
        String notes     = equipNotesField.getText().trim();

        equipSaveBtn.setDisable(true);
        setStatus(equipStatusLabel, "Saving…", "info");

        new Thread(() -> {
            try {
                JsonObject result = equipIsAdding
                    ? ApiService.addEquipment(name, category, qty, condition, location, notes)
                    : ApiService.updateEquipmentFull(
                          currentlyEditingEquip.get("id").getAsInt(),
                          name, category, qty, condition, location, notes);
                Platform.runLater(() -> {
                    equipSaveBtn.setDisable(false);
                    if (result.has("success")) {
                        setStatus(equipStatusLabel,
                            equipIsAdding ? "✓ Equipment added!" : "✓ Equipment updated!", "ok");
                        hideEquipPanel();
                        loadEquipment();
                    } else {
                        setStatus(equipStatusLabel, "✗ " + safeStr(result, "error"), "error");
                    }
                });
            } catch (Exception e) {
                Platform.runLater(() -> {
                    equipSaveBtn.setDisable(false);
                    setStatus(equipStatusLabel, "✗ " + e.getMessage(), "error");
                });
            }
        }).start();
    }

    @FXML private void handleDeleteEquip() {
        if (currentlyEditingEquip == null) return;
        int    id   = currentlyEditingEquip.get("id").getAsInt();
        String name = safeStr(currentlyEditingEquip, "name");

        Alert confirm = new Alert(Alert.AlertType.CONFIRMATION);
        confirm.setTitle("Delete Equipment");
        confirm.setHeaderText("Delete \"" + name + "\" (ID #" + id + ")?");
        confirm.setContentText("This cannot be undone.");
        styleDialog(confirm);
        confirm.showAndWait().ifPresent(btn -> {
            if (btn != ButtonType.OK) return;
            setStatus(equipStatusLabel, "Deleting…", "info");
            new Thread(() -> {
                try {
                    JsonObject result = ApiService.deleteEquipment(id);
                    Platform.runLater(() -> {
                        if (result.has("success")) {
                            setStatus(equipStatusFooter, "🗑 \"" + name + "\" deleted.", "warn");
                            hideEquipPanel();
                            loadEquipment();
                        } else {
                            setStatus(equipStatusLabel, "✗ " + safeStr(result, "error"), "error");
                        }
                    });
                } catch (Exception e) {
                    Platform.runLater(() -> setStatus(equipStatusLabel, "✗ " + e.getMessage(), "error"));
                }
            }).start();
        });
    }

    private void showEquipPanel() {
        equipEditPanel.setVisible(true);
        equipEditPanel.setManaged(true);
    }
    private void hideEquipPanel() {
        equipEditPanel.setVisible(false);
        equipEditPanel.setManaged(false);
        currentlyEditingEquip = null;
        equipIsAdding = false;
    }

    // ══════════════════════════════════════════════
    //  PASSWORD RESET TAB
    // ══════════════════════════════════════════════

    private void setupPasswordResetTab() {
        // Start with only Step 1 visible
        pwrStep2Box.setVisible(false); pwrStep2Box.setManaged(false);
        pwrStep3Box.setVisible(false); pwrStep3Box.setManaged(false);
        pwrCurrentToken = null;
    }

    /** STEP 1 — Admin enters Gmail, server generates token and returns it immediately */
    @FXML private void handleGenerateToken() {
        String email = pwrEmailField.getText().trim();
        if (!GMAIL_PATTERN.matcher(email).matches()) {
            setStatus(pwrStep1Status, "⚠ Enter a valid Gmail address (e.g. member@gmail.com).", "warn");
            return;
        }
        pwrGenerateBtn.setDisable(true);
        setStatus(pwrStep1Status, "Looking up member and generating token…", "info");
        // Hide steps 2+3 in case of retry
        pwrStep2Box.setVisible(false); pwrStep2Box.setManaged(false);
        pwrStep3Box.setVisible(false); pwrStep3Box.setManaged(false);
        pwrCurrentToken = null;

        new Thread(() -> {
            try {
                JsonObject result = ApiService.generatePasswordResetToken(email);
                Platform.runLater(() -> {
                    pwrGenerateBtn.setDisable(false);
                    if (result.has("success")) {
                        String token      = safeStr(result, "token");
                        String memberName = safeStr(result, "full_name");
                        pwrCurrentToken = token;

                        // Format token as spaced groups for easy reading aloud: ABC123 → A B C 1 2 3
                        pwrTokenDisplay.setText(String.join("  ", token.split("")));
                        pwrMemberName.setText("Member: " + memberName + " · " + email);

                        pwrStep2Box.setVisible(true);  pwrStep2Box.setManaged(true);
                        pwrStep3Box.setVisible(true);  pwrStep3Box.setManaged(true);
                        pwrNewPassword.clear();
                        pwrConfirmPassword.clear();
                        pwrPasswordErr.setText("");
                        pwrResetStatus.setText("");
                        setStatus(pwrStep1Status,
                            "✓ Token generated for " + memberName + ". Read it aloud.", "ok");
                    } else {
                        setStatus(pwrStep1Status, "✗ " + safeStr(result, "error"), "error");
                    }
                });
            } catch (Exception e) {
                Platform.runLater(() -> {
                    pwrGenerateBtn.setDisable(false);
                    setStatus(pwrStep1Status, "✗ Connection error: " + e.getMessage(), "error");
                });
            }
        }).start();
    }

    /** STEP 3 — Apply the new password using the stored token */
    @FXML private void handleConfirmReset() {
        if (pwrCurrentToken == null) {
            setStatus(pwrResetStatus, "⚠ Generate a token first (Step 1).", "warn"); return;
        }
        String newPw  = pwrNewPassword.getText();
        String confPw = pwrConfirmPassword.getText();

        if (newPw.length() < 8) {
            pwrPasswordErr.setText("Password must be at least 8 characters."); return;
        }
        if (!newPw.equals(confPw)) {
            pwrPasswordErr.setText("Passwords do not match."); return;
        }
        pwrPasswordErr.setText("");
        pwrResetBtn.setDisable(true);
        setStatus(pwrResetStatus, "Resetting password…", "info");

        String email = pwrEmailField.getText().trim();
        String token = pwrCurrentToken;

        new Thread(() -> {
            try {
                JsonObject result = ApiService.resetPasswordWithToken(email, token, newPw);
                Platform.runLater(() -> {
                    pwrResetBtn.setDisable(false);
                    if (result.has("success")) {
                        setStatus(pwrResetStatus,
                            "✓ Password reset successfully! Member can now log in with their new password.", "ok");
                        // Clear the session so token can't be reused
                        pwrCurrentToken = null;
                        pwrTokenDisplay.setText("— — — — — —");
                        pwrStep2Box.setVisible(false); pwrStep2Box.setManaged(false);
                        pwrStep3Box.setVisible(false); pwrStep3Box.setManaged(false);
                        pwrEmailField.clear();
                        setStatus(pwrStep1Status, "", "info");
                    } else {
                        setStatus(pwrResetStatus, "✗ " + safeStr(result, "error"), "error");
                    }
                });
            } catch (Exception e) {
                Platform.runLater(() -> {
                    pwrResetBtn.setDisable(false);
                    setStatus(pwrResetStatus, "✗ Connection error: " + e.getMessage(), "error");
                });
            }
        }).start();
    }

    /** Reset entire tab back to Step 1 */
    @FXML private void handleCancelReset() {
        pwrCurrentToken = null;
        pwrEmailField.clear();
        pwrNewPassword.clear();
        pwrConfirmPassword.clear();
        pwrTokenDisplay.setText("— — — — — —");
        pwrPasswordErr.setText("");
        pwrStep1Status.setText("");
        pwrResetStatus.setText("");
        pwrStep2Box.setVisible(false); pwrStep2Box.setManaged(false);
        pwrStep3Box.setVisible(false); pwrStep3Box.setManaged(false);
        pwrGenerateBtn.setDisable(false);
        pwrResetBtn.setDisable(false);
    }

    // ══════════════════════════════════════════════
    //  ANALYTICS TAB
    // ══════════════════════════════════════════════

    private void setupAnalyticsTab() {
        anBrkType   .setCellValueFactory(r -> str(r, "membership_type"));
        anBrkCount  .setCellValueFactory(r -> str(r, "total"));
        anBrkActive .setCellValueFactory(r -> str(r, "active"));
        anBrkExpired.setCellValueFactory(r -> str(r, "expired"));
        anBrkRevenue.setCellValueFactory(r -> new SimpleStringProperty(
            "₱" + formatAmount(safeStr(r.getValue(), "revenue"))));

        anChkName      .setCellValueFactory(r -> str(r, "full_name"));
        anChkMembership.setCellValueFactory(r -> str(r, "membership_type"));
        anChkType      .setCellValueFactory(r -> str(r, "checkin_type"));
        anChkTime      .setCellValueFactory(r -> new SimpleStringProperty(
            formatDateTime(safeStr(r.getValue(), "checked_in_at"))));

        anBreakdownTable.setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        anCheckinsTable .setColumnResizePolicy(TableView.CONSTRAINED_RESIZE_POLICY_FLEX_LAST_COLUMN);
        anBreakdownTable.setItems(anBreakdownData);
        anCheckinsTable .setItems(anCheckinsData);
    }

    private void loadAnalytics() {
        int adminId = SessionManager.getInstance().getAdminId();
        new Thread(() -> {
            try {
                JsonObject ov = ApiService.getAnalyticsOverview(adminId);
                JsonArray  bk = ApiService.getMembershipBreakdown(adminId);
                JsonArray  ci = ApiService.getRecentCheckins(adminId);
                Platform.runLater(() -> {
                    anTotalMembers .setText(safeStr(ov, "total_members"));
                    anActiveMembers.setText(safeStr(ov, "active_members"));
                    anExpiredMembers.setText(safeStr(ov, "expired_members"));
                    anExpiringSoon .setText(safeStr(ov, "expiring_soon"));
                    anTodayCheckins.setText(safeStr(ov, "today_checkins"));
                    anRevenue.setText("₱" + formatAmount(safeStr(ov, "total_revenue")));

                    anBreakdownData.clear();
                    bk.forEach(e -> anBreakdownData.add(e.getAsJsonObject()));

                    anCheckinsData.clear();
                    ci.forEach(e -> anCheckinsData.add(e.getAsJsonObject()));

                    setStatus(analyticsStatus,
                        "Last updated: " + LocalTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")), "info");
                });
            } catch (Exception e) {
                Platform.runLater(() -> setStatus(analyticsStatus, "✗ " + e.getMessage(), "error"));
            }
        }).start();
    }
    @FXML
private void handleLogout() {
    shutdown();
    SessionManager.getInstance().clear();
    try {
        java.net.URL fxmlUrl = getClass().getResource("/fittechfrontdesk/resources/Login.fxml");
        javafx.fxml.FXMLLoader loader = new javafx.fxml.FXMLLoader(fxmlUrl);
        javafx.scene.Parent root = loader.load();
        javafx.stage.Stage stage = (javafx.stage.Stage) welcomeLabel.getScene().getWindow();
        javafx.scene.Scene scene = new javafx.scene.Scene(root, 500, 500);
        scene.getStylesheets().add(
            getClass().getResource("/fittechfrontdesk/resources/style.css").toExternalForm());
        stage.setMaximized(false);
        stage.setResizable(true);
        stage.setScene(scene);
        stage.setWidth(516);
        stage.setHeight(570);
        stage.setResizable(false);
        stage.setTitle("FitTech Front Desk — Login");
        stage.centerOnScreen();
        stage.setOnCloseRequest(null);
    } catch (Exception e) {
        e.printStackTrace();
    }
}
    @FXML private void handleRefreshAnalytics() { loadAnalytics(); }

    // ══════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════

    private SimpleStringProperty str(
            TableColumn.CellDataFeatures<JsonObject, String> r, String key) {
        return new SimpleStringProperty(safeStr(r.getValue(), key));
    }

    private String safeStr(JsonObject obj, String key) {
        if (obj == null || !obj.has(key) || obj.get(key).isJsonNull()) return "";
        return obj.get(key).getAsString();
    }

    private String formatTime(String t) {
        if (t == null || t.isEmpty()) return "—";
        try {
            return LocalTime.parse(t).format(DateTimeFormatter.ofPattern("h:mm a"));
        } catch (Exception e) { return t; }
    }

    /** Formats a MySQL datetime string (yyyy-MM-dd HH:mm:ss) to a short readable form */
    private String formatDateTime(String dt) {
        if (dt == null || dt.isEmpty()) return "—";
        try {
            return LocalDateTime.parse(dt.replace(" ", "T"))
                    .format(DateTimeFormatter.ofPattern("MMM d, yyyy"));
        } catch (Exception e) { return dt; }
    }

    /** Formats a MySQL date string (yyyy-MM-dd) to a human-readable form e.g. Jan 16, 2026 */
    private String formatDate(String d) {
        if (d == null || d.isEmpty()) return "—";
        try {
            return LocalDate.parse(d).format(DateTimeFormatter.ofPattern("MMM d, yyyy"));
        } catch (Exception e) { return d; }
    }

    /** Formats a numeric string to comma-separated amount with 2 decimal places */
    private String formatAmount(String raw) {
        try { return String.format("%,.2f", Double.parseDouble(raw)); }
        catch (Exception e) { return raw; }
    }

    private void setStatus(Label label, String msg, String type) {
        label.setText(msg);
        label.setStyle(switch (type) {
            case "ok"    -> "-fx-text-fill: #7cff6b; -fx-font-size: 12;";
            case "error" -> "-fx-text-fill: #ff5555; -fx-font-size: 12;";
            case "warn"  -> "-fx-text-fill: #ffd700; -fx-font-size: 12;";
            default      -> "-fx-text-fill: #aaaaaa; -fx-font-size: 12;";
        });
    }

    private void styleDialog(Dialog<?> dlg) {
        dlg.getDialogPane().setStyle("-fx-background-color: #1a1a1a; -fx-text-fill: white;");
        dlg.setGraphic(null);  // remove the ? / info icon
        dlg.getDialogPane().setGraphic(null);
        // null-guard: lookup returns null if dialog isn't rendered yet
        javafx.scene.Node contentLabel = dlg.getDialogPane().lookup(".content.label");
        if (contentLabel != null) contentLabel.setStyle("-fx-text-fill: #cccccc;");
        // also style via show listener in case node isn't available yet
        dlg.getDialogPane().sceneProperty().addListener((obs, oldScene, newScene) -> {
            if (newScene != null) {
                javafx.scene.Node n = dlg.getDialogPane().lookup(".content.label");
                if (n != null) n.setStyle("-fx-text-fill: #cccccc;");
            }
        });
    }

    /** Called when the stage is closed — shuts down background threads cleanly */
    public void shutdown() {
        if (scheduler != null && !scheduler.isShutdown()) scheduler.shutdownNow();
    }
}
