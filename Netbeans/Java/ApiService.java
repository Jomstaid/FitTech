package fittechfrontdesk.service;

import com.google.gson.*;
import fittechfrontdesk.model.SessionManager;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;

public class ApiService {

    public static final String BASE = "https://fittech.space";

    // AUTH
    public static JsonObject login(String username, String password) throws Exception {
        JsonObject body = new JsonObject();
        body.addProperty("username", username);
        body.addProperty("password", password);
        return post(BASE + "/auth.php?action=login", body.toString());
    }

    // CHECK-IN
    public static JsonObject checkin(int memberId) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("member_id", memberId);
        return post(BASE + "/admin.php?action=checkin&admin_id=" + adminId, body.toString());
    }

    // WALK-IN
    public static JsonObject logWalkin(String name, double amount) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("name",   name);
        body.addProperty("amount", amount);
        return post(BASE + "/admin.php?action=walkin&admin_id=" + adminId, body.toString());
    }

    // MEMBERS
    public static JsonArray getAllMembers(int adminId) throws Exception {
        return getArray(BASE + "/members.php?action=list&admin_id=" + adminId);
    }

    public static JsonObject registerMember(String fullName, String username,
            String email, String phone, String password,
            String gender, String birthdate, String membership) throws Exception {
        JsonObject body = new JsonObject();
        body.addProperty("full_name",       fullName);
        body.addProperty("username",        username);
        body.addProperty("email",           email);
        body.addProperty("phone",           phone);
        body.addProperty("password",        password);
        body.addProperty("gender",          gender);
        body.addProperty("birthdate",       birthdate);
        body.addProperty("membership_type", membership);
        return post(BASE + "/auth.php?action=register", body.toString());
    }

    // UPDATE MEMBER (admin)
    public static JsonObject updateMember(int memberId, String fullName, String username,
            String email, String phone, String gender, String birthdate,
            String membershipType, String status, String expiryDate) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("id",              memberId);
        body.addProperty("full_name",       fullName);
        body.addProperty("username",        username);
        body.addProperty("email",           email);
        body.addProperty("phone",           phone);
        body.addProperty("gender",          gender);
        body.addProperty("birthdate",       birthdate);
        body.addProperty("membership_type", membershipType);
        body.addProperty("status",          status);
        body.addProperty("expiry_date",     expiryDate);
        return post(BASE + "/members.php?action=update&admin_id=" + adminId, body.toString());
    }

    // ANALYTICS
    public static JsonObject getOverview(int adminId) throws Exception {
        return getObject(BASE + "/admin.php?action=overview&admin_id=" + adminId);
    }

    // ANALYTICS TAB — full overview with member counts and revenue
    public static JsonObject getAnalyticsOverview(int adminId) throws Exception {
        return getObject(BASE + "/admin.php?action=analytics_overview&admin_id=" + adminId);
    }

    // ANALYTICS TAB — membership breakdown (per type: total, active, expired, revenue)
    public static JsonArray getMembershipBreakdown(int adminId) throws Exception {
        return getArray(BASE + "/admin.php?action=membership_breakdown&admin_id=" + adminId);
    }

    // ANALYTICS TAB — recent check-ins (last 20)
    public static JsonArray getRecentCheckins(int adminId) throws Exception {
        return getArray(BASE + "/admin.php?action=recent_checkins&admin_id=" + adminId);
    }

    // EQUIPMENT — list all equipment
    public static JsonArray getEquipment(int adminId) throws Exception {
        return getArray(BASE + "/equipment.php?action=list&admin_id=" + adminId);
    }

    // EQUIPMENT — add new item (full fields)
    public static JsonObject addEquipment(String name, String category,
        int quantity, String condition, String location, String notes) throws Exception {
    int adminId = SessionManager.getInstance().getAdminId();
    JsonObject body = new JsonObject();
    body.addProperty("name",             name);
    body.addProperty("category",         category);
    body.addProperty("condition_status", condition);   // was "condition"
    body.addProperty("location",         location);
    body.addProperty("notes",            notes);
    body.addProperty("added_by",         adminId);
    return post(BASE + "/equipment.php?action=create&admin_id=" + adminId, body.toString());
}

    // EQUIPMENT — update full record (condition + all fields)
    public static JsonObject updateEquipmentFull(int equipId, String name, String category,
        int quantity, String condition, String location, String notes) throws Exception {
    int adminId = SessionManager.getInstance().getAdminId();
    JsonObject body = new JsonObject();
    body.addProperty("id",               equipId);
    body.addProperty("name",             name);
    body.addProperty("category",         category);
    body.addProperty("condition_status", condition);   // was "condition"
    body.addProperty("location",         location);
    body.addProperty("notes",            notes);
    return post(BASE + "/equipment.php?action=update&admin_id=" + adminId, body.toString());
}

    // EQUIPMENT — legacy condition/notes-only update (kept for compatibility)
    public static JsonObject updateEquipment(int equipId, String condition, String notes) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("id",        equipId);
        body.addProperty("condition", condition);
        body.addProperty("notes",     notes);
        return post(BASE + "/equipment.php?action=update&admin_id=" + adminId, body.toString());
    }

    // EQUIPMENT — delete
    public static JsonObject deleteEquipment(int equipId) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("id",       equipId);
        body.addProperty("admin_id", adminId);
        return post(BASE + "/equipment.php?action=delete&admin_id=" + adminId, body.toString());
    }

    // PASSWORD RESET — admin-side: generate token for member by email
    // Server creates a reset token, stores it, and returns it directly (no email sent)
    // because this is a face-to-face front-desk flow.
    public static JsonObject generatePasswordResetToken(String email) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("email",    email);
        body.addProperty("admin_id", adminId);
        // Returns: { "success": true, "token": "ABC123", "full_name": "John Doe" }
        return post(BASE + "/auth.php?action=generate_reset_token", body.toString());
    }

    // PASSWORD RESET — apply new password using the token the admin read to member
    public static JsonObject resetPasswordWithToken(String email, String token, String newPassword) throws Exception {
        JsonObject body = new JsonObject();
        body.addProperty("email",        email);
        body.addProperty("token",        token);
        body.addProperty("new_password", newPassword);
        // Returns: { "success": true } or { "error": "..." }
        return post(BASE + "/auth.php?action=reset_password", body.toString());
    }

    // SCHEDULE
    public static JsonArray getTodayBookings(int adminId) throws Exception {
        return getArray(BASE + "/schedule.php?action=all&admin_id=" + adminId);
    }

    // CART ANALYTICS — per-member pending cart data (item count, value, product breakdown)
    public static JsonArray getCartAnalytics(int adminId) throws Exception {
        return getArray(BASE + "/shop.php?action=cart_analytics&admin_id=" + adminId);
    }

    // PENDING ORDERS — one row per order (for approve/reject in dashboard)
    public static JsonArray getPendingOrders(int adminId) throws Exception {
        return getArray(BASE + "/shop.php?action=pending_orders&admin_id=" + adminId);
    }

    // APPROVE ORDER
    public static JsonObject approveOrder(int orderId) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("order_id", orderId);
        body.addProperty("admin_id", adminId);
        return post(BASE + "/shop.php?action=approve_order", body.toString());
    }

    // REJECT ORDER
    public static JsonObject rejectOrder(int orderId) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("order_id", orderId);
        body.addProperty("admin_id", adminId);
        return post(BASE + "/shop.php?action=reject_order", body.toString());
    }

    // PURCHASE ANALYTICS — per-member confirmed spend history (total spent, items bought, dates)
    public static JsonArray getPurchaseAnalytics(int adminId) throws Exception {
        return getArray(BASE + "/shop.php?action=purchase_analytics&admin_id=" + adminId);
    }

    // RENEWAL REQUESTS — list pending or all (filter = "pending" or "all")
    public static JsonArray getRenewalRequests(int adminId, String filter) throws Exception {
        return getArray(BASE + "/renewal.php?action=pending&admin_id=" + adminId
                        + "&filter=" + filter);
    }

    // RENEWAL — approve
    public static JsonObject approveRenewal(int requestId) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("request_id", requestId);
        body.addProperty("admin_id",   adminId);
        return post(BASE + "/renewal.php?action=approve", body.toString());
    }

    // RENEWAL — reject
    public static JsonObject rejectRenewal(int requestId, String note) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("request_id", requestId);
        body.addProperty("admin_id",   adminId);
        body.addProperty("note",       note);
        return post(BASE + "/renewal.php?action=reject", body.toString());
    }

    // RENEWAL — refund (unwinds stacked renewals)
    public static JsonObject refundRenewal(int requestId, String refundNote) throws Exception {
        int adminId = SessionManager.getInstance().getAdminId();
        JsonObject body = new JsonObject();
        body.addProperty("request_id",  requestId);
        body.addProperty("admin_id",    adminId);
        body.addProperty("refund_note", refundNote);
        return post(BASE + "/renewal.php?action=refund", body.toString());
    }

    // RENEWAL — count pending (badge/notification)
    public static JsonObject getRenewalPendingCount(int adminId) throws Exception {
        return getObject(BASE + "/renewal.php?action=count_pending&admin_id=" + adminId);
    }

    // HTTP HELPERS
    public static JsonObject post(String urlStr, String jsonBody) throws Exception {
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setRequestMethod("POST");
        conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
        conn.setDoOutput(true);
        conn.setConnectTimeout(6000);
        conn.setReadTimeout(12000);
        try (OutputStream os = conn.getOutputStream()) {
            os.write(jsonBody.getBytes(StandardCharsets.UTF_8));
        }
        int code = conn.getResponseCode();
        InputStream is = (code >= 200 && code < 300) ? conn.getInputStream() : conn.getErrorStream();
        String raw = new String(is.readAllBytes(), StandardCharsets.UTF_8).trim();
        int j = raw.indexOf('{'); if (j > 0) raw = raw.substring(j);
        return JsonParser.parseString(raw).getAsJsonObject();
    }

    public static JsonArray getArray(String urlStr) throws Exception {
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setRequestMethod("GET");
        conn.setConnectTimeout(6000);
        conn.setReadTimeout(12000);
        String raw = new String(conn.getInputStream().readAllBytes(), StandardCharsets.UTF_8).trim();
        int j = raw.indexOf('['); if (j > 0) raw = raw.substring(j);
        return JsonParser.parseString(raw).getAsJsonArray();
    }

    public static JsonObject getObject(String urlStr) throws Exception {
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setRequestMethod("GET");
        conn.setConnectTimeout(6000);
        conn.setReadTimeout(12000);
        String raw = new String(conn.getInputStream().readAllBytes(), StandardCharsets.UTF_8).trim();
        int j = raw.indexOf('{'); if (j > 0) raw = raw.substring(j);
        return JsonParser.parseString(raw).getAsJsonObject();
    }
}