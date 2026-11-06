import 'package:flutter/material.dart';

class MasarColors {
  static const primary = Color(0xFF1769E8);
  static const blue = Color(0xFF168FE5);
  static const navy = Color(0xFF102B59);
  static const background = Color(0xFFF5F8FC);
  static const border = Color(0xFFE2EAF5);
  static const green = Color(0xFF16B86A);
  static const red = Color(0xFFEF4444);
  static const orange = Color(0xFFF59E0B);
  static const purple = Color(0xFF8B5CF6);
  static const muted = Color(0xFF718096);
}

class MasarTheme {
  static ThemeData get light => ThemeData(
    useMaterial3: true,
    fontFamily: 'Cairo',
    scaffoldBackgroundColor: MasarColors.background,
    colorScheme: ColorScheme.fromSeed(
      seedColor: MasarColors.primary,
      primary: MasarColors.primary,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: Colors.white,
      foregroundColor: MasarColors.navy,
      elevation: 0,
      centerTitle: false,
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: const BorderSide(color: MasarColors.border),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      hintStyle: const TextStyle(color: MasarColors.muted, fontSize: 13),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(13),
        borderSide: const BorderSide(color: MasarColors.border),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(13),
        borderSide: const BorderSide(color: MasarColors.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(13),
        borderSide: const BorderSide(color: MasarColors.primary, width: 1.5),
      ),
    ),
    textTheme: const TextTheme(
      headlineSmall: TextStyle(
        fontWeight: FontWeight.w800,
        color: MasarColors.navy,
      ),
      titleLarge: TextStyle(
        fontWeight: FontWeight.w800,
        color: MasarColors.navy,
      ),
      titleMedium: TextStyle(
        fontWeight: FontWeight.w700,
        color: MasarColors.navy,
      ),
      bodyMedium: TextStyle(color: MasarColors.navy),
    ),
  );
}
