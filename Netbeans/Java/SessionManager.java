package fittechfrontdesk.model;

/**
 * Singleton that holds the currently logged-in admin's data
 * for the duration of the desktop session.
 */
public class SessionManager {

    private static SessionManager instance;

    private int    adminId;
    private String adminName;
    private String adminUsername;

    private SessionManager() {}

    public static SessionManager getInstance() {
        if (instance == null) instance = new SessionManager();
        return instance;
    }

    public void setAdmin(int id, String name, String username) {
        this.adminId       = id;
        this.adminName     = name;
        this.adminUsername = username;
    }

    public int    getAdminId()       { return adminId; }
    public String getAdminName()     { return adminName; }
    public String getAdminUsername() { return adminUsername; }

    public void clear() {
        adminId       = 0;
        adminName     = null;
        adminUsername = null;
    }
}
