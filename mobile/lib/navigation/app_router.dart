import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../features/auth/application/auth_controller.dart';
import '../features/auth/presentation/login_screen.dart';
import '../features/dashboard/presentation/dashboard_screen.dart';
import '../features/modules/application/module_controller.dart';
import '../features/modules/presentation/module_screen.dart';
import '../features/polls/presentation/polls_screen.dart';
import '../features/tickets/presentation/create_ticket_screen.dart';

class SmartSusedRouter extends StatefulWidget {
  const SmartSusedRouter({super.key});

  @override
  State<SmartSusedRouter> createState() => _SmartSusedRouterState();
}

class _SmartSusedRouterState extends State<SmartSusedRouter> {
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    final authController = context.read<AuthController>();
    final moduleController = context.read<ModuleController>();
    final routes = <GoRoute>[
      GoRoute(
        path: '/dashboard',
        builder: (context, state) => const DashboardScreen(),
      ),
      GoRoute(
        path: '/tickets',
        builder: (context, state) => const CreateTicketScreen(),
      ),
      GoRoute(path: '/polls', builder: (context, state) => const PollsScreen()),
      GoRoute(
        path: '/communication',
        builder: (context, state) => const ModuleScreen(
          title: 'Oznamy',
          message: 'Oznamy vášho bytového domu sa čoskoro zobrazia tu.',
          icon: LucideIcons.megaphone,
        ),
      ),
      GoRoute(
        path: '/meters',
        builder: (context, state) => const ModuleScreen(
          title: 'Odpočty',
          message: 'Odpočty meradiel sa čoskoro zobrazia tu.',
          icon: LucideIcons.gauge,
        ),
      ),
    ];

    _router = GoRouter(
      initialLocation: '/dashboard',
      refreshListenable: Listenable.merge([authController, moduleController]),
      redirect: (context, state) {
        final isAuthenticated = authController.isAuthenticated;
        final isLogin = state.matchedLocation == '/login';

        if (!isAuthenticated && !isLogin) return '/login';
        if (isAuthenticated && isLogin) return '/dashboard';
        final modules = moduleController.status!;
        final enabled = switch (state.matchedLocation) {
          '/tickets' => modules.helpdesk,
          '/polls' => modules.polls,
          '/communication' => modules.communication,
          '/meters' => modules.meters,
          _ => true,
        };
        if (isAuthenticated && !enabled) return '/dashboard';
        return null;
      },
      errorBuilder: (context, state) => const Scaffold(
        body: Center(child: Text('Táto stránka nie je dostupná.')),
      ),
      routes: [
        GoRoute(
          path: '/login',
          builder: (context, state) => const LoginScreen(),
        ),
        ShellRoute(
          builder: (context, state, child) => ResidentNavigationShell(
            location: state.matchedLocation,
            child: child,
          ),
          routes: routes,
        ),
        GoRoute(
          path: '/',
          redirect: (context, state) =>
              context.read<AuthController>().isAuthenticated
              ? '/dashboard'
              : '/login',
        ),
      ],
    );
  }

  @override
  void dispose() {
    _router.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp.router(
    title: 'SmartSused',
    debugShowCheckedModeBanner: false,
    theme: Theme.of(context),
    routerConfig: _router,
  );
}

class ResidentNavigationShell extends StatelessWidget {
  const ResidentNavigationShell({
    required this.location,
    required this.child,
    super.key,
  });

  final String location;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final moduleStatus = context.watch<ModuleController>().status!;
    final destinations = <({String path, String label, IconData icon})>[
      (path: '/dashboard', label: 'Nástenka', icon: LucideIcons.home),
      if (moduleStatus.communication)
        (path: '/communication', label: 'Oznamy', icon: LucideIcons.megaphone),
      if (moduleStatus.helpdesk)
        (path: '/tickets', label: 'Poruchy', icon: LucideIcons.ticket),
      if (moduleStatus.polls)
        (path: '/polls', label: 'Ankety', icon: LucideIcons.barChart),
      if (moduleStatus.meters)
        (path: '/meters', label: 'Odpočty', icon: LucideIcons.gauge),
    ];
    final selectedIndex = destinations.indexWhere(
      (destination) => destination.path == location,
    );

    return Scaffold(
      appBar: AppBar(
        title: const Text('SmartSused'),
        actions: [
          IconButton(
            tooltip: 'Odhlásiť sa',
            onPressed: () => context.read<AuthController>().logout(),
            icon: const Icon(LucideIcons.logOut, size: 22),
          ),
        ],
      ),
      body: Column(
        children: [
          if (context.watch<ModuleController>().refreshError != null)
            MaterialBanner(
              content: const Text(
                'Stav dostupných modulov sa nepodarilo obnoviť.',
              ),
              leading: const Icon(LucideIcons.cloudOff, size: 20),
              actions: [
                TextButton(
                  onPressed: context.read<ModuleController>().refresh,
                  child: const Text('Skúsiť znova'),
                ),
              ],
            ),
          Expanded(child: child),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: selectedIndex < 0 ? 0 : selectedIndex,
        onDestinationSelected: (index) => context.go(destinations[index].path),
        destinations: [
          for (final destination in destinations)
            NavigationDestination(
              icon: Icon(destination.icon, size: 22),
              label: destination.label,
            ),
        ],
      ),
    );
  }
}
